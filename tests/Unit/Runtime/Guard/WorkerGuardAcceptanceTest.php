<?php

declare(strict_types=1);

namespace Erebor\Mithril\Tests\Unit\Runtime\Guard;

use Erebor\Mithril\Container;
use Erebor\Mithril\Contracts\HttpApplication;
use Erebor\Mithril\Http\Request;
use Erebor\Mithril\Http\Response;
use Erebor\Mithril\Runtime\Guard\FindingSeverity;
use Erebor\Mithril\Runtime\Guard\GuardConfig;
use Erebor\Mithril\Runtime\Guard\GuardConfigException;
use Erebor\Mithril\Runtime\Guard\GuardCycleContext;
use Erebor\Mithril\Runtime\Guard\GuardDecision;
use Erebor\Mithril\Runtime\Guard\GuardMode;
use Erebor\Mithril\Runtime\Guard\GuardProbe;
use Erebor\Mithril\Runtime\Guard\GuardVocabulary;
use Erebor\Mithril\Runtime\Guard\NullWorkerGuard;
use Erebor\Mithril\Runtime\Guard\Policy\GuardPolicy;
use Erebor\Mithril\Runtime\Guard\Policy\RecyclingCombiner;
use Erebor\Mithril\Runtime\Guard\Probe\MemoryTrendProbe;
use Erebor\Mithril\Runtime\Guard\Probe\ScopedContainerProbe;
use Erebor\Mithril\Runtime\Guard\ProbeWorkerGuard;
use Erebor\Mithril\Runtime\Guard\StaticWatchpoint;
use Erebor\Mithril\Runtime\Guard\WorkerGuard;
use Erebor\Mithril\Runtime\Guard\WorkerGuardFactory;
use Erebor\Mithril\Runtime\InMemoryBridge;
use Erebor\Mithril\Runtime\Recycling\MaxRequestsPolicy;
use Erebor\Mithril\Runtime\Recycling\RecyclingDecision;
use Erebor\Mithril\Runtime\Worker;
use Erebor\Mithril\Runtime\WorkerStopReason;
use Erebor\Mithril\Tests\Fixtures\Guard\SyntheticUserContext;
use PHPUnit\Framework\TestCase;

final class WorkerGuardAcceptanceTest extends TestCase
{
    protected function tearDown(): void
    {
        SyntheticUserContext::reset();
        UnwatchedStatic::$value = 'kept';
    }

    public function testU01OffRunsNoProbesAndDoesNotChangeResponses(): void
    {
        $spy = new SpyGuard();
        $requests = [$this->request('/a'), $this->request('/b')];
        $withGuard = $this->runWorker(new GuardFixtureApp(dirtyStatic: true, clearStatic: true), $requests, $spy);
        $plain = $this->runWorker(new GuardFixtureApp(dirtyStatic: true, clearStatic: true), $requests, null);

        $this->assertSame(0, $spy->calls);
        $this->assertFalse($withGuard['result']->stopReason === WorkerStopReason::Recycled);
        $this->assertSame($this->bodies($plain['bridge']), $this->bodies($withGuard['bridge']));
        $this->assertFalse($withGuard['bridge']->metadata()[0]->recycle);
        $this->assertFalse($withGuard['bridge']->metadata()[1]->recycle);
    }

    public function testU02ScopeEndsOncePerRequestWithoutCleanupFinding(): void
    {
        $guard = $this->guard(GuardMode::Observe);
        $run = $this->runWorker(new GuardFixtureApp(), [$this->request('/a'), $this->request('/b')], $guard);

        $this->assertSame(2, $run['app']->cleanups);
        $this->assertSame('clean', $guard->report()->result);
        $this->assertSame(0, $guard->report()->toArray()['summary']['criticals']);
    }

    public function testU03CleanWatchpointRestoresBaseline(): void
    {
        $guard = $this->guard(GuardMode::Audit);
        $run = $this->runWorker(
            new GuardFixtureApp(dirtyStatic: true, clearStatic: true),
            [$this->request('/a'), $this->request('/b')],
            $guard,
        );

        $this->assertSame([false, false], $this->carried($run['bridge']));
        $this->assertSame('clean', $guard->report()->result);
        $this->assertSame(0, $guard->report()->auditExitCode(true));
    }

    public function testU04ResidualStaticIsStructuredAndFailsStrictAudit(): void
    {
        $guard = $this->guard(GuardMode::Audit);
        $run = $this->runWorker(
            new GuardFixtureApp(dirtyStatic: true, clearStatic: false),
            [$this->request('/a'), $this->request('/b')],
            $guard,
        );

        $report = $guard->report();
        $this->assertSame([false, true], $this->carried($run['bridge']));
        $this->assertSame('findings', $report->result);
        $this->assertSame(1, $report->auditExitCode(true));
        $this->assertSame(GuardVocabulary::STATE_RETAINED, $report->findings[0]->code);
        $this->assertSame('static_watchpoints', $report->findings[0]->probeId);
        $this->assertSame(FindingSeverity::Critical, $report->findings[0]->severity);
        $this->assertStringNotContainsString('synthetic-secret-token', json_encode($report->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testU05SingletonAndWorkerLifetimeAreNotViolations(): void
    {
        $guard = $this->guard(GuardMode::Audit);
        $run = $this->runWorker(
            new GuardFixtureApp(touchIgnored: true, clearStatic: true),
            [$this->request('/a'), $this->request('/b')],
            $guard,
        );

        $bodies = $this->decoded($run['bridge']);
        $this->assertSame($bodies[0]['singleton_id'], $bodies[1]['singleton_id']);
        $this->assertSame('worker-kept', SyntheticUserContext::$workerLabel);
        $this->assertSame('changed', UnwatchedStatic::$value);
        $this->assertSame('clean', $guard->report()->result);
    }

    public function testU06HandleExceptionPreserves500AndEndsScope(): void
    {
        $guard = $this->guard(GuardMode::Observe);
        $app = new GuardFixtureApp(throwOn: '/boom', clearStatic: true);
        $run = $this->runWorker($app, [$this->request('/boom'), $this->request('/ok')], $guard);

        $this->assertSame(500, $run['bridge']->responses()[0]->getStatusCode());
        $this->assertSame(200, $run['bridge']->responses()[1]->getStatusCode());
        $this->assertSame(2, $app->cleanups);
        $this->assertSame(WorkerStopReason::Stopped, $run['result']->stopReason);
        $this->assertStringNotContainsString('synthetic-secret-token', (string) $run['bridge']->responses()[0]->getContent());
    }

    public function testU07ProbeExceptionDoesNotFailObserve(): void
    {
        $guard = new ProbeWorkerGuard(
            GuardMode::Observe,
            [new BoomProbe(), new ScopedContainerProbe()],
            new GuardPolicy(false),
        );
        $run = $this->runWorker(new GuardFixtureApp(), [$this->request('/a')], $guard);

        $this->assertSame(200, $run['bridge']->responses()[0]->getStatusCode());
        $this->assertSame(WorkerStopReason::Stopped, $run['result']->stopReason);
        $this->assertFalse($run['bridge']->metadata()[0]->recycle);
        $encoded = json_encode($guard->report()->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString(GuardVocabulary::PROBE_ERROR, $encoded);
        $this->assertStringNotContainsString('synthetic-secret-token', $encoded);
        $this->assertSame(2, $guard->report()->auditExitCode(true));
    }

    public function testU08ScopeCleanupFailureKeepsOriginalReason(): void
    {
        $guard = $this->guard(GuardMode::Enforce, true);
        $run = $this->runWorker(
            new GuardFixtureApp(dirtyStatic: true, clearStatic: false, failCleanup: true),
            [$this->request('/a'), $this->request('/b')],
            $guard,
        );

        $this->assertSame(WorkerStopReason::ScopeCleanupFailure, $run['result']->stopReason);
        $this->assertSame('scope_cleanup_failure', $run['bridge']->metadata()[0]->recycleReason);
        $this->assertSame(1, $run['result']->requestsHandled);
        $this->assertNotSame(GuardVocabulary::REASON_STATE, $run['result']->recycleReason);
    }

    public function testU09MemoryTrendWarnsWithoutRecycle(): void
    {
        $real = 0;
        $probe = new MemoryTrendProbe(1, 3, 0, static function () use (&$real): array {
            $real += 10;

            return ['usage' => $real, 'real' => $real, 'peak' => $real];
        });
        $guard = new ProbeWorkerGuard(GuardMode::Enforce, [$probe], new GuardPolicy(true));
        $run = $this->runWorker(
            new GuardFixtureApp(),
            [$this->request('/1'), $this->request('/2'), $this->request('/3')],
            $guard,
        );

        $this->assertSame(WorkerStopReason::Stopped, $run['result']->stopReason);
        $this->assertFalse($run['bridge']->metadata()[2]->recycle);
        $this->assertSame(GuardVocabulary::MEMORY_TREND, $guard->report()->findings[0]->code);
        $this->assertSame(0, $guard->report()->auditExitCode(false));
        $this->assertSame(1, $guard->report()->auditExitCode(true));
    }

    public function testU10EnforceRecyclesOnlyAfterTheResponse(): void
    {
        $guard = $this->guard(GuardMode::Enforce, true);
        $run = $this->runWorker(
            new GuardFixtureApp(dirtyStatic: true, clearStatic: false),
            [$this->request('/a'), $this->request('/b')],
            $guard,
        );

        $this->assertSame(1, $run['result']->requestsHandled);
        $this->assertSame(WorkerStopReason::Recycled, $run['result']->stopReason);
        $this->assertSame(GuardVocabulary::REASON_STATE, $run['result']->recycleReason);
        $this->assertCount(1, $run['bridge']->responses());
        $body = json_decode((string) $run['bridge']->responses()[0]->getContent(), true);
        $this->assertFalse($body['carried']);
        $this->assertArrayNotHasKey('findings', $body);
        $this->assertSame(GuardVocabulary::REASON_STATE, $run['bridge']->metadata()[0]->recycleReason);
    }

    public function testU11ExistingRecycleReasonIsNotReplaced(): void
    {
        $guard = $this->guard(GuardMode::Enforce, true);
        $run = $this->runWorker(
            new GuardFixtureApp(dirtyStatic: true, clearStatic: false),
            [$this->request('/a'), $this->request('/b')],
            $guard,
            new MaxRequestsPolicy(1),
        );

        $this->assertSame('max_requests', $run['bridge']->metadata()[0]->recycleReason);
        $this->assertSame(GuardVocabulary::STATE_RETAINED, $guard->report()->findings[0]->code);
        $this->assertNotSame('max_requests', $guard->report()->findings[0]->code);
    }

    public function testU12SyntheticSecretIsAbsentFromReportAndMetadata(): void
    {
        $guard = $this->guard(GuardMode::Enforce, true);
        $run = $this->runWorker(
            new GuardFixtureApp(dirtyStatic: true, clearStatic: false),
            [$this->request('/a')],
            $guard,
        );

        $blob = json_encode([
            'report' => $guard->report()->toArray(),
            'reason' => $run['bridge']->metadata()[0]->recycleReason,
            'body' => (string) $run['bridge']->responses()[0]->getContent(),
        ], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('synthetic-secret-token', $blob);
        $this->assertSame(GuardVocabulary::REASON_STATE, $run['bridge']->metadata()[0]->recycleReason);
    }

    public function testObserveDoesNotRecycleVerifiedState(): void
    {
        $guard = $this->guard(GuardMode::Observe, true);
        $run = $this->runWorker(
            new GuardFixtureApp(dirtyStatic: true, clearStatic: false),
            [$this->request('/a'), $this->request('/b')],
            $guard,
        );

        $this->assertSame(2, $run['result']->requestsHandled);
        $this->assertFalse($run['bridge']->metadata()[0]->recycle);
        $this->assertSame('findings', $guard->report()->result);
    }

    public function testUnsafeConfigIsRejectedAsAWhole(): void
    {
        $this->expectException(GuardConfigException::class);
        GuardConfig::fromArray([
            'mode' => 'enforce',
            'policy' => [
                'recycle_on_verified_state_violation' => true,
                'recycle_on_suspected_memory_growth' => true,
            ],
        ]);
    }

    public function testHttpWorkerRejectsAuditAndInvalidFiles(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wg-' . bin2hex(random_bytes(4));
        mkdir($dir . DIRECTORY_SEPARATOR . 'config', 0700, true);

        $this->assertInstanceOf(NullWorkerGuard::class, WorkerGuardFactory::fromConfig(GuardConfig::httpWorker($dir)));

        file_put_contents($dir . '/config/worker_guard.php', "<?php\nreturn ['mode' => 'audit'];\n");
        $fallback = GuardConfig::httpWorker($dir);
        $this->assertSame(GuardMode::Off, $fallback->mode);

        file_put_contents($dir . '/config/worker_guard.php', "<?php\nreturn ['mode' => 'observe', 'sample_every_n_requests' => 10];\n");
        $observe = GuardConfig::loadFile($dir . '/config/worker_guard.php', true);
        $this->assertSame(GuardMode::Observe, $observe->mode);

        file_put_contents($dir . '/config/worker_guard.php', "<?php\nreturn 'nope';\n");
        $this->assertSame(GuardMode::Off, GuardConfig::httpWorker($dir)->mode);
    }

    public function testCombinerPrefersScopeFailureOverGuard(): void
    {
        $combined = (new RecyclingCombiner())->combine(
            RecyclingDecision::recycle('max_requests'),
            new GuardDecision([], true, GuardVocabulary::REASON_STATE),
            true,
        );

        $this->assertSame('scope_cleanup_failure', $combined->reason);
    }

    /**
     * @param list<Request> $requests
     * @return array{app: GuardFixtureApp, bridge: InMemoryBridge, result: \Erebor\Mithril\Runtime\WorkerResult}
     */
    private function runWorker(
        GuardFixtureApp $app,
        array $requests,
        ?WorkerGuard $guard,
        ?MaxRequestsPolicy $policy = null,
    ): array {
        $bridge = new InMemoryBridge($requests);
        $worker = new Worker($app, $bridge, recyclingPolicy: $policy, guard: $guard);
        $result = $worker->runResult();

        return ['app' => $app, 'bridge' => $bridge, 'result' => $result];
    }

    private function guard(GuardMode $mode, bool $recycle = false): ProbeWorkerGuard
    {
        $config = new GuardConfig(
            mode: $mode,
            maxProbesPerCycle: 8,
            sampleEveryNRequests: 10,
            reportPath: 'var/worker-guard',
            recycleOnVerifiedStateViolation: $recycle,
            watchpoints: [
                new StaticWatchpoint(SyntheticUserContext::class, 'userId', 'request'),
                new StaticWatchpoint(SyntheticUserContext::class, 'token', 'request'),
                new StaticWatchpoint(SyntheticUserContext::class, 'workerLabel', 'worker'),
            ],
            enableMemoryProbe: false,
        );
        $guard = WorkerGuardFactory::fromConfig($config);
        $this->assertInstanceOf(ProbeWorkerGuard::class, $guard);

        return $guard;
    }

    private function request(string $uri): Request
    {
        return new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri], [], [], []);
    }

    /**
     * @return list<string>
     */
    private function bodies(InMemoryBridge $bridge): array
    {
        return array_map(static fn (Response $response): string => (string) $response->getContent(), $bridge->responses());
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

    /**
     * @return list<array<string, mixed>>
     */
    private function decoded(InMemoryBridge $bridge): array
    {
        return array_map(
            static fn (Response $response): array => json_decode((string) $response->getContent(), true),
            $bridge->responses(),
        );
    }
}

final class UnwatchedStatic
{
    public static string $value = 'kept';
}

final class SpyGuard implements WorkerGuard
{
    public int $calls = 0;

    public function enabled(): bool
    {
        return false;
    }

    public function mode(): GuardMode
    {
        return GuardMode::Off;
    }

    public function before(GuardCycleContext $context): void
    {
        $this->calls++;
    }

    public function after(GuardCycleContext $context): GuardDecision
    {
        $this->calls++;

        return GuardDecision::none();
    }
}

final class BoomProbe implements GuardProbe
{
    public function id(): string
    {
        return 'boom_probe';
    }

    public function before(GuardCycleContext $context): void
    {
        throw new \RuntimeException('synthetic-secret-token boom');
    }

    public function after(GuardCycleContext $context): array
    {
        return [];
    }

    public function coverage(): string
    {
        return 'covered';
    }
}

final class GuardFixtureApp implements HttpApplication
{
    public int $bootCount = 0;

    public int $cleanups = 0;

    private Container $container;

    private bool $booted = false;

    public function __construct(
        private readonly bool $dirtyStatic = false,
        private readonly bool $clearStatic = true,
        private readonly bool $failCleanup = false,
        private readonly ?string $throwOn = null,
        private readonly bool $touchIgnored = false,
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
        $shared->id = 'shared-fixed';
        $this->container->singleton('shared', $shared);

        $app = $this;
        $this->container->scoped('ticket', static function () use ($app) {
            return new class($app) {
                public function __construct(private GuardFixtureApp $app)
                {
                }

                public function cleanup(): void
                {
                    $this->app->noteCleanup();
                }
            };
        });

        $this->booted = true;
    }

    public function noteCleanup(): void
    {
        $this->cleanups++;
        if ($this->failCleanup) {
            throw new \RuntimeException('synthetic-secret-token cleanup');
        }
        if ($this->clearStatic) {
            SyntheticUserContext::clearRequestState();
        }
    }

    public function handle(Request $request): Response
    {
        $this->container->get('ticket');
        $shared = $this->container->get('shared');
        if ($this->throwOn !== null && $request->getPath() === $this->throwOn) {
            throw new \RuntimeException('synthetic-secret-token handle');
        }

        $carried = SyntheticUserContext::$userId !== null;
        if ($this->dirtyStatic) {
            SyntheticUserContext::$userId = 'synthetic-secret-token';
            SyntheticUserContext::setToken('synthetic-secret-token');
        }
        if ($this->touchIgnored) {
            SyntheticUserContext::$workerLabel = 'worker-kept';
            UnwatchedStatic::$value = 'changed';
        }

        return Response::json([
            'carried' => $carried,
            'singleton_id' => $shared->id,
            'path' => $request->getPath(),
        ]);
    }

    public function getContainer(): Container
    {
        return $this->container;
    }
}
