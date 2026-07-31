<?php

declare(strict_types=1);

namespace Erebor\Mithril\Tests\Unit;

use Erebor\Mithril\Container;
use Erebor\Mithril\Contracts\HttpApplication;
use Erebor\Mithril\Http\Request;
use Erebor\Mithril\Http\Response;
use Erebor\Mithril\Runtime\InMemoryBridge;
use Erebor\Mithril\Runtime\Worker;
use PHPUnit\Framework\TestCase;

class WorkerTest extends TestCase
{
    public function testBootsOnceAndServesMultipleRequestsWithScopedIsolation(): void
    {
        $app = new FakeWarmApp();
        $bridge = new InMemoryBridge([
            $this->request('/a'),
            $this->request('/b'),
        ]);

        $served = (new Worker($app, $bridge))->run();

        $this->assertSame(1, $app->bootCount);
        $this->assertSame(2, $served);
        $this->assertCount(2, $bridge->responses());

        $bodyA = json_decode((string) $bridge->responses()[0]->getContent(), true);
        $bodyB = json_decode((string) $bridge->responses()[1]->getContent(), true);

        $this->assertSame($bodyA['singleton_id'], $bodyB['singleton_id']);
        $this->assertNotSame($bodyA['scoped_id'], $bodyB['scoped_id']);
        $this->assertSame('/a', $bodyA['path']);
        $this->assertSame('/b', $bodyB['path']);
    }

    public function testMaxRequestsStopsLoop(): void
    {
        $app = new FakeWarmApp();
        $bridge = new InMemoryBridge([
            $this->request('/1'),
            $this->request('/2'),
            $this->request('/3'),
        ]);

        $served = (new Worker($app, $bridge, maxRequests: 2))->run();

        $this->assertSame(2, $served);
        $this->assertCount(2, $bridge->responses());
    }

    public function testEmptyBridgeServesZero(): void
    {
        $app = new FakeWarmApp();
        $bridge = new InMemoryBridge([]);

        $served = (new Worker($app, $bridge))->run();

        $this->assertSame(1, $app->bootCount);
        $this->assertSame(0, $served);
    }

    private function request(string $uri): Request
    {
        return new Request(
            [],
            [],
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri],
            [],
            [],
            []
        );
    }
}

final class FakeWarmApp implements HttpApplication
{
    public int $bootCount = 0;

    private Container $container;
    private bool $booted = false;

    public function __construct()
    {
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
            strict: false
        );

        $this->container->scoped('ticket', function () {
            $obj = new \stdClass();
            $obj->id = 'scoped-' . uniqid('', true);
            return $obj;
        });

        $this->booted = true;
    }

    public function handle(Request $request): Response
    {
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
