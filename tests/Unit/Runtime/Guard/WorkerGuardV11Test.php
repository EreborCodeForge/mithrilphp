<?php

declare(strict_types=1);

namespace Erebor\Mithril\Tests\Unit\Runtime\Guard;

use Erebor\Mithril\Container;
use Erebor\Mithril\Contracts\HttpApplication;
use Erebor\Mithril\Http\Request;
use Erebor\Mithril\Http\Response;
use Erebor\Mithril\Runtime\Guard\FindingConfidence;
use Erebor\Mithril\Runtime\Guard\GuardFinding;
use Erebor\Mithril\Runtime\Guard\GuardBootNotice;
use Erebor\Mithril\Runtime\Guard\GuardConfig;
use Erebor\Mithril\Runtime\Guard\GuardConfigException;
use Erebor\Mithril\Runtime\Guard\GuardCycleContext;
use Erebor\Mithril\Runtime\Guard\GuardEventLog;
use Erebor\Mithril\Runtime\Guard\GuardMode;
use Erebor\Mithril\Runtime\Guard\GuardProbe;
use Erebor\Mithril\Runtime\Guard\GuardVocabulary;
use Erebor\Mithril\Runtime\Guard\NullWorkerGuard;
use Erebor\Mithril\Runtime\Guard\Policy\GuardPolicy;
use Erebor\Mithril\Runtime\Guard\ProbeWorkerGuard;
use Erebor\Mithril\Runtime\Guard\StaticWatchpoint;
use Erebor\Mithril\Runtime\Guard\WorkerGuardFactory;
use Erebor\Mithril\Runtime\InMemoryBridge;
use Erebor\Mithril\Runtime\Recycling\MaxRequestsPolicy;
use Erebor\Mithril\Runtime\Recycling\MemoryLimitPolicy;
use Erebor\Mithril\Runtime\Worker;
use Erebor\Mithril\Runtime\WorkerStopReason;
use Erebor\Mithril\Tests\Fixtures\Guard\SyntheticUserContext;
use PHPUnit\Framework\TestCase;

final class WorkerGuardV11Test extends TestCase
{
    protected function tearDown(): void
    {
        SyntheticUserContext::reset();
        V11NonScalar::$box = new \stdClass();
        GuardBootNotice::reset();
    }

    public function testU01OffDoesNotRunProbes(): void
    {
        $probe = new V11CountingProbe(false);
        $guard = new ProbeWorkerGuard(GuardMode::Off, [$probe], new GuardPolicy(false));
        $with = $this->runRequests(new V11App(true, true), 2, $guard);
        $plain = $this->runRequests(new V11App(true, true), 2, null);

        $this->assertSame(0, $probe->runs);
        $this->assertSame($this->bodies($plain), $this->bodies($with));
        $this->assertFalse($with['bridge']->metadata()[0]->recycle);
        $this->assertInstanceOf(NullWorkerGuard::class, WorkerGuardFactory::fromConfig(GuardConfig::off()));
    }

    public function testU02OffConfigDoesNotBuildProbesOnTheHotPath(): void
    {
        $dir = $this->configDir(['mode' => 'off', 'static_watchpoints' => [[
            'class' => SyntheticUserContext::class,
            'property' => 'userId',
            'lifetime' => 'request',
        ]]]);
        $config = GuardConfig::httpWorker($dir);
        $guard = WorkerGuardFactory::fromConfig($config);

        $this->assertInstanceOf(NullWorkerGuard::class, $guard);
        $this->assertNull($config->bootDiagnostic);
        $run = $this->runRequests(new V11App(true, false), 1, $guard);
        $this->assertFalse($run['bridge']->metadata()[0]->recycle);
        $this->assertSame(WorkerStopReason::Stopped, $run['result']->stopReason);
    }

    public function testU03ObserveSamplesOnlySampleableProbes(): void
    {
        $sampleable = new V11CountingProbe(true, 'memory_trend');
        $required = new V11CountingProbe(false, 'scoped_container');
        $guard = new ProbeWorkerGuard(GuardMode::Observe, [$sampleable, $required], new GuardPolicy(true), 8, 10);
        $this->runRequests(new V11App(false, true), 20, $guard);

        $this->assertSame(2, $sampleable->runs);
        $this->assertSame(20, $required->runs);
        $this->assertFalse($guard->report()->findings !== [] && $guard->report()->toArray()['summary']['criticals'] > 0);
    }

    public function testU04EnforceDoesNotSampleRequiredProbesAndFlagFalseDoesNotRecycle(): void
    {
        $sampleable = new V11CountingProbe(true, 'memory_trend');
        $guard = new ProbeWorkerGuard(
            GuardMode::Enforce,
            [$sampleable, new \Erebor\Mithril\Runtime\Guard\Probe\ExplicitStaticStateProbe([
                new StaticWatchpoint(SyntheticUserContext::class, 'userId', 'request'),
            ])],
            new GuardPolicy(false),
            8,
            10,
        );
        $run = $this->runRequests(new V11App(true, false), 10, $guard);

        $this->assertSame(1, $sampleable->runs);
        $this->assertSame(10, $run['result']->requestsHandled);
        $this->assertFalse($run['bridge']->metadata()[0]->recycle);
        $this->assertSame(FindingConfidence::Verified, $guard->report()->findings[0]->confidence);
    }

    public function testU05AuditDoesNotSkipCycles(): void
    {
        $sampleable = new V11CountingProbe(true, 'memory_trend');
        $guard = new ProbeWorkerGuard(GuardMode::Audit, [$sampleable], new GuardPolicy(false), 8, 10);
        $this->runRequests(new V11App(false, true), 3, $guard);

        $this->assertSame(3, $sampleable->runs);
    }

    public function testU06PermittedOrWeakHashChangeIsNotVerifiedScopeBreach(): void
    {
        V11NonScalar::$box = new \stdClass();
        $guard = new ProbeWorkerGuard(
            GuardMode::Enforce,
            [new \Erebor\Mithril\Runtime\Guard\Probe\ExplicitStaticStateProbe([
                new StaticWatchpoint(V11NonScalar::class, 'box', 'request'),
                new StaticWatchpoint(SyntheticUserContext::class, 'workerLabel', 'worker'),
            ])],
            new GuardPolicy(true),
        );
        $app = new V11App(false, false, static function (): void {
            SyntheticUserContext::$workerLabel = 'kept-on-purpose';
            V11NonScalar::$box = new \ArrayObject();
        });
        $run = $this->runRequests($app, 1, $guard);
        $finding = $guard->report()->findings[0];

        $this->assertSame(FindingConfidence::Observed, $finding->confidence);
        $this->assertNotSame('scoped_container', $finding->probeId);
        $this->assertFalse($run['bridge']->metadata()[0]->recycle);
        $this->assertSame('kept-on-purpose', SyntheticUserContext::$workerLabel);
    }

    public function testU07VerifiedRecycleRequiresEnforceAndOptIn(): void
    {
        SyntheticUserContext::reset();
        $observe = $this->staticGuard(GuardMode::Observe, true);
        $observeRun = $this->runRequests(new V11App(true, false), 1, $observe);
        $this->assertFalse($observeRun['bridge']->metadata()[0]->recycle);

        SyntheticUserContext::reset();
        $noFlag = $this->staticGuard(GuardMode::Enforce, false);
        $noFlagRun = $this->runRequests(new V11App(true, false), 1, $noFlag);
        $this->assertFalse($noFlagRun['bridge']->metadata()[0]->recycle);

        SyntheticUserContext::reset();
        $enforce = $this->staticGuard(GuardMode::Enforce, true);
        $enforceRun = $this->runRequests(new V11App(true, false), 2, $enforce);
        $this->assertSame(GuardVocabulary::REASON_STATE, $enforceRun['bridge']->metadata()[0]->recycleReason);
        $this->assertSame(1, $enforceRun['result']->requestsHandled);
        $this->assertSame(FindingConfidence::Verified, $enforce->report()->findings[0]->confidence);
    }

    public function testU08ExistingPolicyReasonWinsOverGuard(): void
    {
        $guard = $this->staticGuard(GuardMode::Enforce, true);
        $bridge = new InMemoryBridge([$this->request('/a'), $this->request('/b')]);
        $result = (new Worker(
            new V11App(true, false),
            $bridge,
            recyclingPolicy: new MemoryLimitPolicy(1),
            guard: $guard,
        ))->runResult();

        $this->assertSame('memory_limit', $bridge->metadata()[0]->recycleReason);
        $this->assertSame('memory_limit', $result->recycleReason);
        $this->assertNotEmpty($guard->report()->findings);
        $this->assertSame(1, $result->requestsHandled);
    }

    public function testU09ScopeCleanupFailureStaysAheadOfTheGuard(): void
    {
        $guard = $this->staticGuard(GuardMode::Enforce, true);
        $bridge = new InMemoryBridge([$this->request('/a'), $this->request('/b')]);
        $result = (new Worker(
            new V11App(true, false, failCleanup: true),
            $bridge,
            recyclingPolicy: new MaxRequestsPolicy(1),
            guard: $guard,
        ))->runResult();

        $this->assertSame(WorkerStopReason::ScopeCleanupFailure, $result->stopReason);
        $this->assertSame('scope_cleanup_failure', $bridge->metadata()[0]->recycleReason);
        $this->assertCount(1, $bridge->responses());
    }

    public function testU10ProbeExceptionDoesNotFailTheResponse(): void
    {
        $guard = new ProbeWorkerGuard(GuardMode::Observe, [new V11BoomProbe()], new GuardPolicy(true));
        $run = $this->runRequests(new V11App(false, true), 1, $guard);

        $this->assertSame(200, $run['bridge']->responses()[0]->getStatusCode());
        $this->assertFalse($run['bridge']->metadata()[0]->recycle);
        $this->assertSame(GuardVocabulary::PROBE_ERROR, $guard->report()->findings[0]->code);
        $this->assertStringNotContainsString('synthetic-secret-token', json_encode($guard->report()->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testU11InvalidHttpConfigDisablesGuardAndWarnsOnce(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wg11-' . bin2hex(random_bytes(4));
        mkdir($dir . DIRECTORY_SEPARATOR . 'config', 0700, true);
        file_put_contents(
            $dir . '/config/worker_guard.php',
            "<?php\nreturn ['mode' => 'audit', 'secret' => 'synthetic-secret-token'];\n",
        );

        $config = GuardConfig::httpWorker($dir);
        $this->assertSame(GuardMode::Off, $config->mode);
        $this->assertSame(GuardConfig::HTTP_DISABLED_DIAGNOSTIC, $config->bootDiagnostic);
        $this->assertStringNotContainsString('synthetic-secret-token', (string) $config->bootDiagnostic);
        $this->assertInstanceOf(NullWorkerGuard::class, WorkerGuardFactory::fromConfig($config));

        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        GuardBootNotice::emit($config, $stream);
        GuardBootNotice::emit($config, $stream);
        rewind($stream);
        $text = stream_get_contents($stream);
        $this->assertSame(1, substr_count((string) $text, 'durin doctor --strict'));
        $this->assertStringNotContainsString('synthetic-secret-token', (string) $text);

        try {
            GuardConfig::fromArray(['mode' => 'observe', 'leak' => 'synthetic-secret-token']);
            $this->fail('invalid config should throw');
        } catch (GuardConfigException $e) {
            $this->assertStringNotContainsString('synthetic-secret-token', $e->getMessage());
        }
    }

    public function testU14SyntheticSecretIsAbsentFromLogJsonAndStats(): void
    {
        $lines = [];
        $guard = $this->staticGuard(GuardMode::Observe, false);
        $guard->setEventLog(new GuardEventLog(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }));
        $this->runRequests(new V11App(true, false), 1, $guard);

        $blob = json_encode([
            'report' => $guard->report()->toArray(),
            'stats' => $guard->stats(),
            'log' => $lines,
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('synthetic-secret-token', $blob);
        $this->assertStringNotContainsString('xxh128', $blob);
        $this->assertNotEmpty($lines);
        $decoded = json_decode($lines[0], true);
        $this->assertSame('mithril.worker_guard', $decoded['component']);
        $this->assertSame('guard_state_retained', $decoded['code']);
        $this->assertArrayNotHasKey('safe_summary', $decoded);
    }

    public function testRetainedFindingsStayBoundedWhileCountersGrow(): void
    {
        $guard = new ProbeWorkerGuard(GuardMode::Observe, [
            new V11RepeatFindingProbe('static_watchpoints', GuardFinding::stateRetained('static_watchpoints')),
            new V11RepeatFindingProbe('memory_trend', GuardFinding::memoryTrend()),
        ], new GuardPolicy(false));
        $context = new GuardCycleContext(1, 1, GuardMode::Observe, 'c', 0);
        for ($i = 0; $i < 400; $i++) {
            $guard->before($context);
            $guard->after($context);
        }

        $report = $guard->report();
        $codes = array_map(static fn (GuardFinding $finding): string => $finding->code, $report->findings);
        sort($codes);

        $this->assertSame(
            [GuardVocabulary::MEMORY_TREND, GuardVocabulary::STATE_RETAINED],
            $codes,
        );
        $this->assertCount(2, $report->findings);
        $this->assertSame('findings', $report->result);
        $this->assertSame(1, $report->auditExitCode(false));
        $stats = $guard->stats();
        $this->assertSame(400, $stats['findings_by_code'][GuardVocabulary::STATE_RETAINED]);
        $this->assertSame(400, $stats['findings_by_code'][GuardVocabulary::MEMORY_TREND]);
        $this->assertSame(0, $stats['findings_by_code'][GuardVocabulary::PROBE_ERROR]);
        $this->assertSame(400, $report->toArray()['summary']['cycles']);
    }

    private function staticGuard(GuardMode $mode, bool $recycle): ProbeWorkerGuard
    {
        $config = new GuardConfig(
            mode: $mode,
            maxProbesPerCycle: 8,
            sampleEveryNRequests: 10,
            reportPath: 'var/worker-guard',
            recycleOnVerifiedStateViolation: $recycle,
            watchpoints: [new StaticWatchpoint(SyntheticUserContext::class, 'userId', 'request')],
            enableMemoryProbe: false,
        );
        $guard = WorkerGuardFactory::fromConfig($config);
        $this->assertInstanceOf(ProbeWorkerGuard::class, $guard);

        return $guard;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function configDir(array $data): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wg11-' . bin2hex(random_bytes(4));
        mkdir($dir . DIRECTORY_SEPARATOR . 'config', 0700, true);
        file_put_contents(
            $dir . '/config/worker_guard.php',
            "<?php\nreturn " . var_export($data, true) . ";\n",
        );

        return $dir;
    }

    /**
     * @return array{bridge: InMemoryBridge, result: \Erebor\Mithril\Runtime\WorkerResult}
     */
    private function runRequests(V11App $app, int $count, ?\Erebor\Mithril\Runtime\Guard\WorkerGuard $guard): array
    {
        $requests = [];
        for ($i = 0; $i < $count; $i++) {
            $requests[] = $this->request('/r' . $i);
        }
        $bridge = new InMemoryBridge($requests);
        $result = (new Worker($app, $bridge, guard: $guard))->runResult();

        return ['bridge' => $bridge, 'result' => $result];
    }

    private function request(string $uri): Request
    {
        return new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri], [], [], []);
    }

    /**
     * @param array{bridge: InMemoryBridge, result: \Erebor\Mithril\Runtime\WorkerResult} $run
     * @return list<string>
     */
    private function bodies(array $run): array
    {
        return array_map(
            static fn (Response $response): string => (string) $response->getContent(),
            $run['bridge']->responses(),
        );
    }
}

final class V11NonScalar
{
    public static object|null $box = null;
}

final class V11CountingProbe implements GuardProbe
{
    public int $runs = 0;

    public function __construct(
        private readonly bool $sampled,
        private readonly string $probeId = 'counting_probe',
    ) {}

    public function id(): string
    {
        return $this->probeId;
    }

    public function before(GuardCycleContext $context): void
    {
        $this->runs++;
    }

    public function after(GuardCycleContext $context): array
    {
        return [];
    }

    public function coverage(): string
    {
        return 'covered';
    }

    public function sampleable(): bool
    {
        return $this->sampled;
    }

    public function prepare(): void
    {
    }
}

final class V11RepeatFindingProbe implements GuardProbe
{
    public function __construct(
        private readonly string $probeId,
        private readonly GuardFinding $finding,
    ) {}

    public function id(): string
    {
        return $this->probeId;
    }

    public function before(GuardCycleContext $context): void
    {
    }

    public function after(GuardCycleContext $context): array
    {
        return [$this->finding];
    }

    public function coverage(): string
    {
        return 'covered';
    }

    public function sampleable(): bool
    {
        return false;
    }

    public function prepare(): void
    {
    }
}

final class V11BoomProbe implements GuardProbe
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

    public function sampleable(): bool
    {
        return false;
    }

    public function prepare(): void
    {
    }
}

final class V11App implements HttpApplication
{
    private Container $container;

    private bool $booted = false;

    public function __construct(
        private readonly bool $dirty = false,
        private readonly bool $clear = true,
        private readonly mixed $onHandle = null,
        private readonly bool $failCleanup = false,
    ) {
        $this->container = new Container();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $app = $this;
        $this->container->scoped('ticket', static function () use ($app) {
            return new class($app) {
                public function __construct(private V11App $app)
                {
                }

                public function cleanup(): void
                {
                    $this->app->cleanup();
                }
            };
        });
        $this->booted = true;
    }

    public function cleanup(): void
    {
        if ($this->failCleanup) {
            throw new \RuntimeException('synthetic-secret-token cleanup');
        }
        if ($this->clear) {
            SyntheticUserContext::clearRequestState();
            V11NonScalar::$box = new \stdClass();
        }
    }

    public function handle(Request $request): Response
    {
        $this->container->get('ticket');
        if ($this->dirty) {
            SyntheticUserContext::$userId = 'synthetic-secret-token';
        }
        if ($this->onHandle !== null) {
            ($this->onHandle)();
        }

        return Response::json(['path' => $request->getPath()]);
    }

    public function getContainer(): Container
    {
        return $this->container;
    }
}
