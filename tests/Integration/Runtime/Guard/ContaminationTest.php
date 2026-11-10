<?php

declare(strict_types=1);

namespace Erebor\Mithril\Tests\Integration\Runtime\Guard;

use Erebor\Mithril\Http\Request;
use Erebor\Mithril\Http\Response;
use Erebor\Mithril\Runtime\Eregion\Exceptions\ProtocolException;
use Erebor\Mithril\Runtime\Eregion\ResponseMapper;
use Erebor\Mithril\Runtime\Guard\GuardMode;
use Erebor\Mithril\Runtime\Guard\GuardVocabulary;
use Erebor\Mithril\Runtime\Guard\ProbeWorkerGuard;
use Erebor\Mithril\Runtime\InMemoryBridge;
use Erebor\Mithril\Runtime\Worker;
use Erebor\Mithril\Runtime\WorkerMetadata;
use Erebor\Mithril\Runtime\WorkerMetadataAwareBridge;
use Erebor\Mithril\Runtime\WorkerStopReason;
use Erebor\Mithril\Tests\Fixtures\Guard\SyntheticUserContext;
use Erebor\Mithril\Tests\Unit\Runtime\Guard\GuardFixtureApp;
use PHPUnit\Framework\TestCase;

final class ContaminationTest extends TestCase
{
    protected function tearDown(): void
    {
        SyntheticUserContext::reset();
    }

    public function testI01RequestScopedStateDoesNotCrossRequestsOnOneWorker(): void
    {
        $guard = $this->guard(GuardMode::Audit);
        $bridge = new InMemoryBridge([$this->request('/a'), $this->request('/b')]);
        $result = (new Worker(
            new GuardFixtureApp(dirtyStatic: true, clearStatic: true),
            $bridge,
            guard: $guard,
        ))->runResult();

        $this->assertSame(2, $result->requestsHandled);
        $this->assertSame([false, false], $this->carried($bridge));
        $this->assertSame('clean', $guard->report()->result);

        SyntheticUserContext::reset();
        $first = new InMemoryBridge([$this->request('/a')]);
        (new Worker(new GuardFixtureApp(dirtyStatic: true, clearStatic: true), $first, guard: $this->guard(GuardMode::Audit)))->run();
        SyntheticUserContext::reset();
        $second = new InMemoryBridge([$this->request('/b')]);
        $secondGuard = $this->guard(GuardMode::Audit);
        (new Worker(new GuardFixtureApp(dirtyStatic: true, clearStatic: true), $second, guard: $secondGuard))->run();

        $this->assertSame([false], $this->carried($second));
        $this->assertSame('clean', $secondGuard->report()->result);
    }

    public function testI02ResidualStaticIsClassifiedWithoutTheValue(): void
    {
        $guard = $this->guard(GuardMode::Audit);
        $bridge = new InMemoryBridge([$this->request('/a'), $this->request('/b')]);
        (new Worker(
            new GuardFixtureApp(dirtyStatic: true, clearStatic: false),
            $bridge,
            guard: $guard,
        ))->run();

        $this->assertSame([false, true], $this->carried($bridge));
        $encoded = json_encode($guard->report()->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString(GuardVocabulary::STATE_RETAINED, $encoded);
        $this->assertStringNotContainsString('synthetic-secret-token', $encoded);
        $this->assertStringNotContainsString('synthetic-secret-token', (string) $bridge->responses()[1]->getContent());
    }

    public function testI05TransportFailureWinsAndDoesNotRespondTwice(): void
    {
        $bridge = new ExplodingBridge([$this->request('/a')]);
        $result = (new Worker(new GuardFixtureApp(), $bridge, guard: $this->guard(GuardMode::Observe)))->runResult();

        $this->assertSame(WorkerStopReason::ProtocolFailure, $result->stopReason);
        $this->assertSame(1, $bridge->responds);
    }

    public function testI07EregionMetadataStaysCompatible(): void
    {
        $mapper = new ResponseMapper();
        $withReason = $mapper->map('req-1', new Response('ok', 200), new WorkerMetadata(1, 2, 3, true, GuardVocabulary::REASON_STATE));
        $without = $mapper->map('req-2', new Response('ok', 200), new WorkerMetadata(1, 2, 3, false, null));

        $reason = $withReason->toArray();
        $plain = $without->toArray();

        $this->assertSame('response', $reason['type']);
        $this->assertSame(1, $reason['version']);
        $this->assertSame(GuardVocabulary::REASON_STATE, $reason['meta']['recycle_reason']);
        $this->assertSame('ok', $reason['body']);
        $this->assertArrayNotHasKey('findings', $reason);
        $this->assertArrayNotHasKey('recycle_reason', $plain['meta']);
        $this->assertFalse($plain['meta']['recycle']);
        $this->assertSame(['requests_handled', 'memory_usage', 'memory_peak', 'recycle'], array_keys($plain['meta']));
    }

    public function testI08OffMatchesTheBaselineWorker(): void
    {
        $requests = [$this->request('/a'), $this->request('/b')];
        $baseline = new InMemoryBridge($requests);
        (new Worker(new GuardFixtureApp(dirtyStatic: true, clearStatic: true), $baseline))->run();
        SyntheticUserContext::reset();

        $guarded = new InMemoryBridge($requests);
        (new Worker(
            new GuardFixtureApp(dirtyStatic: true, clearStatic: true),
            $guarded,
            guard: new \Erebor\Mithril\Runtime\Guard\NullWorkerGuard(),
        ))->run();

        $this->assertSame(
            array_map(static fn (Response $response): string => (string) $response->getContent(), $baseline->responses()),
            array_map(static fn (Response $response): string => (string) $response->getContent(), $guarded->responses()),
        );
        $this->assertFalse($guarded->metadata()[0]->recycle);
        $this->assertFalse($guarded->metadata()[1]->recycle);
    }

    private function guard(GuardMode $mode): ProbeWorkerGuard
    {
        $config = \Erebor\Mithril\Runtime\Guard\GuardConfig::audit([
            new \Erebor\Mithril\Runtime\Guard\StaticWatchpoint(SyntheticUserContext::class, 'userId', 'request'),
            new \Erebor\Mithril\Runtime\Guard\StaticWatchpoint(SyntheticUserContext::class, 'token', 'request'),
        ]);
        $config = new \Erebor\Mithril\Runtime\Guard\GuardConfig(
            mode: $mode,
            maxProbesPerCycle: $config->maxProbesPerCycle,
            sampleEveryNRequests: $config->sampleEveryNRequests,
            reportPath: $config->reportPath,
            recycleOnVerifiedStateViolation: false,
            watchpoints: $config->watchpoints,
            enableMemoryProbe: false,
        );
        $guard = \Erebor\Mithril\Runtime\Guard\WorkerGuardFactory::fromConfig($config);
        $this->assertInstanceOf(ProbeWorkerGuard::class, $guard);

        return $guard;
    }

    private function request(string $uri): Request
    {
        return new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri], [], [], []);
    }

    /**
     * @return list<bool>
     */
    private function carried(InMemoryBridge $bridge): array
    {
        return array_map(static function (Response $response): bool {
            $body = json_decode((string) $response->getContent(), true);

            return (bool) ($body['carried'] ?? false);
        }, $bridge->responses());
    }
}

final class ExplodingBridge implements WorkerMetadataAwareBridge
{
    /** @var list<Request> */
    private array $queue;

    public int $responds = 0;

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
        $this->responds++;
        throw new ProtocolException('transport down');
    }
}
