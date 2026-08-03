<?php

declare(strict_types=1);

namespace Erebor\Mithril\Tests\Integration\Eregion;

use Erebor\Mithril\Container;
use Erebor\Mithril\Contracts\HttpApplication;
use Erebor\Mithril\Http\Request;
use Erebor\Mithril\Http\Response;
use Erebor\Mithril\Runtime\InMemoryBridge;
use Erebor\Mithril\Runtime\Recycling\MaxRequestsPolicy;
use Erebor\Mithril\Runtime\Worker;
use Erebor\Mithril\Runtime\WorkerMetadata;
use Erebor\Mithril\Runtime\WorkerMetadataAwareBridge;
use Erebor\Mithril\Runtime\WorkerStopReason;
use PHPUnit\Framework\TestCase;

final class WorkerLifecycleTest extends TestCase
{
    public function testBootOnceSingletonPersistsScopedIsolated(): void
    {
        $app = new LifecycleApp();
        $bridge = new MetadataBridge([
            $this->request('/a'),
            $this->request('/b'),
        ]);

        $result = (new Worker($app, $bridge))->runResult();

        $this->assertSame(1, $app->bootCount);
        $this->assertSame(2, $result->requestsHandled);
        $this->assertSame(WorkerStopReason::Stopped, $result->stopReason);

        $a = json_decode((string) $bridge->responses()[0]->getContent(), true);
        $b = json_decode((string) $bridge->responses()[1]->getContent(), true);
        $this->assertSame($a['singleton_id'], $b['singleton_id']);
        $this->assertNotSame($a['scoped_id'], $b['scoped_id']);
    }

    public function testEndScopeRunsAfterApplicationException(): void
    {
        $app = new LifecycleApp(throwOn: '/boom');
        $bridge = new MetadataBridge([
            $this->request('/boom'),
            $this->request('/ok'),
        ]);

        $result = (new Worker($app, $bridge))->runResult();

        $this->assertSame(2, $result->requestsHandled);
        $this->assertSame(500, $bridge->responses()[0]->getStatusCode());
        $this->assertSame(200, $bridge->responses()[1]->getStatusCode());

        // Second request still gets a fresh scoped service → endScope ran after the exception.
        $ok = json_decode((string) $bridge->responses()[1]->getContent(), true);
        $this->assertArrayHasKey('scoped_id', $ok);
    }

    public function testAppHttp500DoesNotForceRecycle(): void
    {
        $app = new LifecycleApp(throwOn: '/boom');
        $bridge = new MetadataBridge([$this->request('/boom')]);

        $result = (new Worker($app, $bridge))->runResult();

        $this->assertSame(WorkerStopReason::Stopped, $result->stopReason);
        $this->assertFalse($bridge->metadata()[0]->recycle);
        $this->assertSame(500, $bridge->responses()[0]->getStatusCode());
    }

    public function testRecycleAfterMaxRequestsSendsFinalResponse(): void
    {
        $app = new LifecycleApp();
        $bridge = new MetadataBridge([
            $this->request('/1'),
            $this->request('/2'),
            $this->request('/3'),
        ]);

        $result = (new Worker(
            $app,
            $bridge,
            maxRequests: 2,
            recyclingPolicy: new MaxRequestsPolicy(2),
        ))->runResult();

        $this->assertSame(2, $result->requestsHandled);
        $this->assertSame(WorkerStopReason::Recycled, $result->stopReason);
        $this->assertSame('max_requests', $result->recycleReason);
        $this->assertCount(2, $bridge->responses());
        $this->assertTrue($bridge->metadata()[1]->recycle);
        $this->assertSame('max_requests', $bridge->metadata()[1]->recycleReason);
        $this->assertSame(10, $result->exitCode()->value);
    }

    public function testEmptyBridgeStopsCleanly(): void
    {
        $app = new LifecycleApp();
        $bridge = new MetadataBridge([]);

        $result = (new Worker($app, $bridge))->runResult();

        $this->assertSame(0, $result->requestsHandled);
        $this->assertSame(WorkerStopReason::Stopped, $result->stopReason);
        $this->assertSame(0, $result->exitCode()->value);
    }

    public function testLegacyBridgeStillWorks(): void
    {
        $app = new LifecycleApp();
        $bridge = new InMemoryBridge([$this->request('/x')]);

        $served = (new Worker($app, $bridge))->run();

        $this->assertSame(1, $served);
        $this->assertCount(1, $bridge->responses());
    }

    private function request(string $uri): Request
    {
        return new Request(
            [],
            [],
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri],
            [],
            [],
            [],
        );
    }
}

final class MetadataBridge implements WorkerMetadataAwareBridge
{
    /** @var list<Request> */
    private array $queue;

    /** @var list<Response> */
    private array $responses = [];

    /** @var list<WorkerMetadata> */
    private array $metadata = [];

    /**
     * @param list<Request> $requests
     */
    public function __construct(array $requests)
    {
        $this->queue = array_values($requests);
    }

    public function next(): ?Request
    {
        if ($this->queue === []) {
            return null;
        }

        return array_shift($this->queue);
    }

    public function respond(Response $response, ?WorkerMetadata $metadata = null): void
    {
        $this->responses[] = $response;
        if ($metadata !== null) {
            $this->metadata[] = $metadata;
        }
    }

    /** @return list<Response> */
    public function responses(): array
    {
        return $this->responses;
    }

    /** @return list<WorkerMetadata> */
    public function metadata(): array
    {
        return $this->metadata;
    }
}

final class LifecycleApp implements HttpApplication
{
    public int $bootCount = 0;

    private Container $container;

    private bool $booted = false;

    public function __construct(
        private readonly ?string $throwOn = null,
    ) {
        $this->container = new Container();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->bootCount++;
        $shared = new \stdClass();
        $shared->id = 'shared-' . uniqid('', true);

        $this->container->loadCompiled(
            factories: [],
            singletons: [],
            preloaded: ['shared' => $shared],
            strict: false,
        );

        $this->container->scoped('ticket', static function () {
            $obj = new \stdClass();
            $obj->id = 'scoped-' . uniqid('', true);
            return $obj;
        });

        $this->booted = true;
    }

    public function handle(Request $request): Response
    {
        if ($this->throwOn !== null && $request->getPath() === $this->throwOn) {
            throw new \RuntimeException('boom');
        }

        $shared = $this->container->get('shared');
        $ticket = $this->container->get('ticket');

        return Response::json([
            'path' => $request->getPath(),
            'singleton_id' => $shared->id,
            'scoped_id' => $ticket->id,
        ]);
    }

    public function getContainer(): Container
    {
        return $this->container;
    }
}
