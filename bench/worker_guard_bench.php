<?php

declare(strict_types=1);

use Erebor\Mithril\Container;
use Erebor\Mithril\Contracts\HttpApplication;
use Erebor\Mithril\Http\Request;
use Erebor\Mithril\Http\Response;
use Erebor\Mithril\Runtime\Guard\GuardConfig;
use Erebor\Mithril\Runtime\Guard\GuardCycleContext;
use Erebor\Mithril\Runtime\Guard\GuardMode;
use Erebor\Mithril\Runtime\Guard\NullWorkerGuard;
use Erebor\Mithril\Runtime\Guard\Probe\ExplicitStaticStateProbe;
use Erebor\Mithril\Runtime\Guard\Probe\MemoryTrendProbe;
use Erebor\Mithril\Runtime\Guard\Probe\ScopedContainerProbe;
use Erebor\Mithril\Runtime\Guard\ProbeWorkerGuard;
use Erebor\Mithril\Runtime\Guard\StaticWatchpoint;
use Erebor\Mithril\Runtime\Guard\WorkerGuard;
use Erebor\Mithril\Runtime\Guard\WorkerGuardFactory;
use Erebor\Mithril\Runtime\InMemoryBridge;
use Erebor\Mithril\Runtime\Worker;
use Erebor\Mithril\Runtime\WorkerMetadata;
use Erebor\Mithril\Runtime\WorkerMetadataAwareBridge;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Reproducible WorkerGuard microbenchmark.
 *
 * The historical script tests/Benchmark/worker_guard_compare.php uses InMemoryBridge,
 * whose array_shift queue is quadratic. This runner uses a cursor so the Guard delta
 * is not hidden inside that queue cost. It does not include Eregion.
 *
 * Usage:
 *   php bench/worker_guard_bench.php --requests=100000 --rounds=10 --label=baseline
 */

final class BenchStatic
{
    public static ?string $userId = null;
}

final class BenchApp implements HttpApplication
{
    private Container $container;

    private bool $booted = false;

    public function __construct(
        private readonly bool $dirty = true,
        private readonly bool $clear = true,
        private readonly int $fakeDbMicros = 0,
    ) {
        $this->container = new Container();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->container->scoped('reset', function () {
            return new class($this) {
                public function __construct(private BenchApp $app)
                {
                }

                public function cleanup(): void
                {
                    if ($this->app->clears()) {
                        BenchStatic::$userId = null;
                    }
                }
            };
        });
        $this->booted = true;
    }

    public function clears(): bool
    {
        return $this->clear;
    }

    public function handle(Request $request): Response
    {
        $this->container->get('reset');
        if ($this->dirty) {
            BenchStatic::$userId = 'bench-user';
        }
        if ($this->fakeDbMicros > 0) {
            usleep($this->fakeDbMicros);
        }

        return Response::json(['ok' => true, 'path' => $request->getPath()]);
    }

    public function getContainer(): Container
    {
        return $this->container;
    }
}

final class CursorBridge implements WorkerMetadataAwareBridge
{
    private int $index = 0;

    /** @var list<int> */
    public array $samplesNs = [];

    private int $started = 0;

    /**
     * @param list<Request> $requests
     */
    public function __construct(private readonly array $requests)
    {
    }

    public function next(): ?Request
    {
        if ($this->index >= count($this->requests)) {
            return null;
        }
        $request = $this->requests[$this->index];
        $this->index++;
        $this->started = hrtime(true);

        return $request;
    }

    public function respond(Response $response, ?WorkerMetadata $metadata = null): void
    {
        $this->samplesNs[] = hrtime(true) - $this->started;
    }
}

$opts = bench_options($argv);
$requestsN = $opts['requests'];
$rounds = $opts['rounds'];
$label = $opts['label'];

$requests = [];
for ($i = 0; $i < $requestsN; $i++) {
    $requests[] = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/bench'], [], [], []);
}

$light = new GuardConfig(
    mode: GuardMode::Observe,
    maxProbesPerCycle: 8,
    sampleEveryNRequests: 1,
    reportPath: 'var/worker-guard',
    recycleOnVerifiedStateViolation: false,
    watchpoints: [new StaticWatchpoint(BenchStatic::class, 'userId', 'request')],
    enableMemoryProbe: true,
);
$sampled = new GuardConfig(
    mode: GuardMode::Observe,
    maxProbesPerCycle: 8,
    sampleEveryNRequests: 10,
    reportPath: 'var/worker-guard',
    recycleOnVerifiedStateViolation: false,
    watchpoints: [new StaticWatchpoint(BenchStatic::class, 'userId', 'request')],
);
$emptyObserve = new GuardConfig(
    mode: GuardMode::Observe,
    maxProbesPerCycle: 8,
    sampleEveryNRequests: 10,
    reportPath: 'var/worker-guard',
    recycleOnVerifiedStateViolation: false,
    watchpoints: [],
    enableScopedProbe: false,
    enableStaticProbe: false,
    enableMemoryProbe: false,
);
$enforce = new GuardConfig(
    mode: GuardMode::Enforce,
    maxProbesPerCycle: 8,
    sampleEveryNRequests: 10,
    reportPath: 'var/worker-guard',
    recycleOnVerifiedStateViolation: true,
    watchpoints: [new StaticWatchpoint(BenchStatic::class, 'userId', 'request')],
    enableMemoryProbe: false,
);

$modes = [
    'none' => static fn (): ?WorkerGuard => null,
    'off' => static fn (): ?WorkerGuard => new NullWorkerGuard(),
    'observe_empty' => static fn (): ?WorkerGuard => WorkerGuardFactory::fromConfig($emptyObserve),
    'observe_light' => static fn (): ?WorkerGuard => WorkerGuardFactory::fromConfig($light),
    'observe_sampled' => static fn (): ?WorkerGuard => WorkerGuardFactory::fromConfig($sampled),
    'enforce_clean' => static fn (): ?WorkerGuard => WorkerGuardFactory::fromConfig($enforce),
];

foreach ($modes as $name => $factory) {
    $guard = $factory();
    bench_once($name . '-warmup', $requests, new BenchApp(), $guard);
    BenchStatic::$userId = null;
}

$samples = [];
for ($round = 0; $round < $rounds; $round++) {
    $order = array_keys($modes);
    mt_srand(1000 + $round);
    shuffle($order);
    foreach ($order as $name) {
        BenchStatic::$userId = null;
        $guard = $modes[$name]();
        $samples[$name][] = bench_once($name, $requests, new BenchApp(), $guard);
    }
}

$violationRequests = [];
for ($i = 0; $i < min(200, $requestsN); $i++) {
    $violationRequests[] = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/violate'], [], [], []);
}
$violationGuard = WorkerGuardFactory::fromConfig($enforce);
$violation = bench_once(
    'enforce_violation',
    $violationRequests,
    new BenchApp(dirty: true, clear: false),
    $violationGuard,
);

$summary = [];
foreach ($samples as $name => $rows) {
    $summary[$name] = bench_summary($rows);
}

$offRps = $summary['off']['rps_p50'] ?? 0.0;
$noneRps = $summary['none']['rps_p50'] ?? 0.0;
$lightRps = $summary['observe_light']['rps_p50'] ?? 0.0;

$report = [
    'label' => $label,
    'generated_at' => gmdate('c'),
    'environment' => bench_environment(),
    'command' => 'php bench/worker_guard_bench.php --requests=' . $requestsN . ' --rounds=' . $rounds . ' --label=' . $label,
    'requests_per_round' => $requestsN,
    'rounds' => $rounds,
    'order' => 'seeded shuffle per round (mt_srand(1000+round)); no rounds dropped',
    'includes_eregion' => false,
    'eregion_http' => [
        'status' => 'N/A',
        'reason' => 'This process is the in-process Mithril loop. Eregion HTTP scenarios are bench/eregion_http_bench.go and are not part of this number.',
    ],
    'hash_micro' => bench_hash_micro(),
    'probe_micro' => bench_probe_micro(),
    'historical_compare_script' => 'php tests/Benchmark/worker_guard_compare.php 2000 5',
    'notes' => [
        'none injects no guard. off injects NullWorkerGuard. They share the Worker loop.',
        'observe_light is the reference light configuration: scoped probe, one request static, memory probe.',
        'enforce_violation is reported separately and is not mixed into healthy throughput.',
        'Targets of 2%, 3 microseconds and 5% are engineering goals, not a claim that this run met them.',
    ],
    'comparison' => [
        'off_vs_none_throughput_drop_percent' => bench_drop($noneRps, $offRps),
        'observe_light_vs_off_throughput_drop_percent' => bench_drop($offRps, $lightRps),
        'observe_light_hook_ns_p50' => $summary['observe_light']['hook_ns_per_request_p50'] ?? null,
    ],
    'summary' => $summary,
    'enforce_violation' => $violation,
    'rounds_raw' => $samples,
];

$dir = dirname(__DIR__) . '/bench/results';
if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
    fwrite(STDERR, "Unable to create bench/results\n");
    exit(1);
}
$path = $dir . '/' . preg_replace('/[^a-z0-9._-]+/i', '-', $label) . '.json';
file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
echo json_encode([
    'wrote' => $path,
    'comparison' => $report['comparison'],
    'summary' => $summary,
    'hash_micro' => $report['hash_micro'],
    'probe_micro' => $report['probe_micro'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

/**
 * @param list<Request> $requests
 * @return array<string, mixed>
 */
function bench_once(string $label, array $requests, HttpApplication $app, ?WorkerGuard $guard): array
{
    $bridge = new CursorBridge($requests);
    $usageBefore = memory_get_usage(false);
    $realBefore = memory_get_usage(true);
    $cpuBefore = bench_cpu_ns();
    $started = hrtime(true);
    (new Worker($app, $bridge, guard: $guard))->run();
    $elapsed = hrtime(true) - $started;
    $cpuAfter = bench_cpu_ns();
    $count = count($requests);
    $seconds = $elapsed / 1_000_000_000;
    $hook = 0;
    $probeOk = 0;
    $probeError = 0;
    $probeDuration = [];
    if ($guard instanceof ProbeWorkerGuard) {
        $stats = $guard->stats();
        $hook = (int) ($stats['hook_duration_ns'] ?? 0);
        $probeOk = (int) ($stats['probe_ok'] ?? 0);
        $probeError = (int) ($stats['probe_error'] ?? 0);
        $probeDuration = is_array($stats['probe_duration_ns'] ?? null) ? $stats['probe_duration_ns'] : [];
    }
    $lat = bench_percentiles($bridge->samplesNs);

    return [
        'label' => $label,
        'requests' => $count,
        'elapsed_ns' => $elapsed,
        'rps' => $seconds > 0 ? round($count / $seconds, 1) : 0.0,
        'latency_ns' => $lat,
        'cpu_ns' => $cpuAfter === null || $cpuBefore === null ? null : $cpuAfter - $cpuBefore,
        'php_alloc_delta' => memory_get_usage(false) - $usageBefore,
        'php_real_delta' => memory_get_usage(true) - $realBefore,
        'php_real' => memory_get_usage(true),
        'hook_duration_ns' => $hook,
        'hook_ns_per_request' => $count > 0 ? (int) round($hook / $count) : 0,
        'probe_ok' => $probeOk,
        'probe_error' => $probeError,
        'probe_duration_ns' => $probeDuration,
    ];
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array<string, mixed>
 */
function bench_summary(array $rows): array
{
    $rps = array_map(static fn (array $row): float => (float) $row['rps'], $rows);
    $hook = array_map(static fn (array $row): int => (int) $row['hook_ns_per_request'], $rows);
    $p95 = array_map(static fn (array $row): int => (int) ($row['latency_ns']['p95'] ?? 0), $rows);

    return [
        'rounds' => count($rows),
        'rps_min' => round(min($rps), 1),
        'rps_p50' => round(bench_quantile($rps, 0.50), 1),
        'rps_p95' => round(bench_quantile($rps, 0.95), 1),
        'rps_max' => round(max($rps), 1),
        'request_latency_ns_p95_median' => (int) round(bench_quantile($p95, 0.50)),
        'hook_ns_per_request_p50' => (int) round(bench_quantile($hook, 0.50)),
        'hook_ns_per_request_min' => min($hook),
        'hook_ns_per_request_max' => max($hook),
    ];
}

/**
 * @param list<int|float> $values
 */
function bench_quantile(array $values, float $q): float
{
    $copy = array_values($values);
    sort($copy);
    $n = count($copy);
    if ($n === 0) {
        return 0.0;
    }
    $index = (int) floor(($n - 1) * $q);

    return (float) $copy[$index];
}

/**
 * @param list<int> $samples
 * @return array{p50: int, p95: int, p99: int}
 */
function bench_percentiles(array $samples): array
{
    if ($samples === []) {
        return ['p50' => 0, 'p95' => 0, 'p99' => 0];
    }
    $copy = $samples;
    sort($copy);
    $at = static function (array $sorted, float $q): int {
        $index = (int) floor((count($sorted) - 1) * $q);

        return (int) $sorted[$index];
    };

    return [
        'p50' => $at($copy, 0.50),
        'p95' => $at($copy, 0.95),
        'p99' => $at($copy, 0.99),
    ];
}

function bench_drop(float $baseline, float $candidate): ?float
{
    if ($baseline <= 0) {
        return null;
    }

    return round((1 - $candidate / $baseline) * 100, 2);
}

/**
 * @return array<string, mixed>
 */
function bench_environment(): array
{
    $opcache = extension_loaded('Zend OPcache');

    return [
        'php' => PHP_VERSION,
        'os' => PHP_OS_FAMILY . ' ' . php_uname('r') . ' ' . php_uname('m'),
        'sapi' => PHP_SAPI,
        'opcache_loaded' => $opcache,
        'opcache_enable' => $opcache ? (string) ini_get('opcache.enable') : 'N/A',
        'opcache_enable_cli' => $opcache ? (string) ini_get('opcache.enable_cli') : 'N/A',
        'jit' => (string) ini_get('opcache.jit'),
        'memory_limit' => (string) ini_get('memory_limit'),
        'cpu_time' => function_exists('getrusage') ? 'getrusage' : 'N/A',
    ];
}

function bench_cpu_ns(): ?int
{
    if (!function_exists('getrusage')) {
        return null;
    }
    $usage = getrusage();
    if (!is_array($usage)) {
        return null;
    }
    $user = ((int) ($usage['ru_utime.tv_sec'] ?? 0)) * 1_000_000_000 + ((int) ($usage['ru_utime.tv_usec'] ?? 0)) * 1_000;
    $sys = ((int) ($usage['ru_stime.tv_sec'] ?? 0)) * 1_000_000_000 + ((int) ($usage['ru_stime.tv_usec'] ?? 0)) * 1_000;

    return $user + $sys;
}

/**
 * @return array<string, mixed>
 */
function bench_hash_micro(): array
{
    $key = random_bytes(32);
    $material = 'string|bench-user';
    $n = 100000;
    $started = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        hash('xxh128', $key . $material);
    }
    $keyed = hrtime(true) - $started;
    $hmac = null;
    $hmacNs = null;
    if (in_array('sha256', hash_hmac_algos(), true)) {
        $started = hrtime(true);
        for ($i = 0; $i < $n; $i++) {
            hash_hmac('sha256', $material, $key);
        }
        $hmac = hrtime(true) - $started;
        $hmacNs = (int) round($hmac / $n);
    }

    return [
        'iterations' => $n,
        'keyed_xxh128_ns' => (int) round($keyed / $n),
        'hmac_sha256_ns' => $hmacNs,
        'choice' => 'keyed xxh128 stays on the hot path; HMAC-SHA256 is recorded and not used per request',
    ];
}

/**
 * Isolated before+after cost. The request loop does not time each probe:
 * that extra clock pair was larger than the probe itself on this host.
 *
 * @return array<string, mixed>
 */
function bench_probe_micro(): array
{
    $iterations = 20000;
    $context = new GuardCycleContext(1, 1, GuardMode::Observe, 'bench', 0);
    $container = new Container();
    $container->scoped('reset', static fn (): \stdClass => new \stdClass());
    $scoped = new ScopedContainerProbe();
    $scoped->attach($container);
    $scoped->prepare();
    $static = new ExplicitStaticStateProbe([new StaticWatchpoint(BenchStatic::class, 'userId', 'request')]);
    $static->prepare();
    BenchStatic::$userId = null;
    $memory = new MemoryTrendProbe(1);
    $memory->prepare();
    $out = [];
    foreach ([
        'scoped_container' => $scoped,
        'static_watchpoints' => $static,
        'memory_trend' => $memory,
    ] as $id => $probe) {
        $probe->before($context);
        $started = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $probe->before($context);
            $probe->after($context);
        }
        $out[$id] = (int) round((hrtime(true) - $started) / $iterations);
    }

    return [
        'iterations' => $iterations,
        'ns_per_before_after' => $out,
        'note' => 'Direct probe calls, outside Worker. One clock pair around the whole loop.',
    ];
}

/**
 * @param list<string> $argv
 * @return array{requests: int, rounds: int, label: string}
 */
function bench_options(array $argv): array
{
    $requests = 100000;
    $rounds = 10;
    $label = 'run';
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--requests=')) {
            $requests = max(100, (int) substr($arg, 11));
        } elseif (str_starts_with($arg, '--rounds=')) {
            $rounds = max(1, (int) substr($arg, 9));
        } elseif (str_starts_with($arg, '--label=')) {
            $label = substr($arg, 8);
        }
    }

    return ['requests' => $requests, 'rounds' => $rounds, 'label' => $label];
}
