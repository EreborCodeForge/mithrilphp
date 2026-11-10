<?php

declare(strict_types=1);

use Erebor\Mithril\Container;
use Erebor\Mithril\Contracts\HttpApplication;
use Erebor\Mithril\Http\Request;
use Erebor\Mithril\Http\Response;
use Erebor\Mithril\Runtime\Guard\GuardConfig;
use Erebor\Mithril\Runtime\Guard\GuardMode;
use Erebor\Mithril\Runtime\Guard\ProbeWorkerGuard;
use Erebor\Mithril\Runtime\Guard\StaticWatchpoint;
use Erebor\Mithril\Runtime\Guard\WorkerGuardFactory;
use Erebor\Mithril\Runtime\InMemoryBridge;
use Erebor\Mithril\Runtime\Worker;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

final class BenchContext
{
    public static ?string $userId = null;
}

final class BenchApp implements HttpApplication
{
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

        $this->container->scoped('reset', static function () {
            return new class {
                public function cleanup(): void
                {
                    BenchContext::$userId = null;
                }
            };
        });
        $this->booted = true;
    }

    public function handle(Request $request): Response
    {
        $this->container->get('reset');
        BenchContext::$userId = 'bench';

        return Response::json(['path' => $request->getPath()]);
    }

    public function getContainer(): Container
    {
        return $this->container;
    }
}

$requests = max(100, (int) ($argv[1] ?? 2000));
$rounds = max(1, (int) ($argv[2] ?? 5));

$observe = new GuardConfig(
    mode: GuardMode::Observe,
    maxProbesPerCycle: 8,
    sampleEveryNRequests: 10,
    reportPath: 'var/worker-guard',
    recycleOnVerifiedStateViolation: false,
    watchpoints: [new StaticWatchpoint(BenchContext::class, 'userId', 'request')],
);

$measure = static function (string $label, ?ProbeWorkerGuard $guard) use ($requests): array {
    $queue = [];
    for ($i = 0; $i < $requests; $i++) {
        $queue[] = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/' . $i], [], [], []);
    }

    $started = hrtime(true);
    $memoryBefore = memory_get_usage(true);
    (new Worker(new BenchApp(), new InMemoryBridge($queue), guard: $guard))->run();
    $elapsed = hrtime(true) - $started;

    return [
        'label' => $label,
        'requests' => $requests,
        'elapsed_ms' => round($elapsed / 1_000_000, 3),
        'rps' => round($requests / ($elapsed / 1_000_000_000), 1),
        'memory_real_after' => memory_get_usage(true),
        'memory_delta' => memory_get_usage(true) - $memoryBefore,
        'stats' => $guard?->stats() ?? ['probe_ok' => 0, 'probe_error' => 0, 'hook_duration_ns' => 0],
    ];
};

$measure('warmup-off', null);
$measure('warmup-observe', WorkerGuardFactory::fromConfig($observe) instanceof ProbeWorkerGuard
    ? WorkerGuardFactory::fromConfig($observe)
    : null);

$samples = ['off' => [], 'observe' => []];
for ($round = 0; $round < $rounds; $round++) {
    $samples['off'][] = $measure('off', null);
    $guard = WorkerGuardFactory::fromConfig($observe);
    $samples['observe'][] = $measure('observe', $guard instanceof ProbeWorkerGuard ? $guard : null);
}

$median = static function (array $rows, string $key): float {
    $values = array_map(static fn (array $row): float => (float) $row[$key], $rows);
    sort($values);
    $count = count($values);
    $mid = intdiv($count, 2);
    if ($count % 2 === 1) {
        return $values[$mid];
    }

    return ($values[$mid - 1] + $values[$mid]) / 2;
};

$offRps = $median($samples['off'], 'rps');
$observeRps = $median($samples['observe'], 'rps');
$delta = $offRps > 0 ? round((1 - $observeRps / $offRps) * 100, 2) : null;

echo json_encode([
    'host' => PHP_OS . ' ' . php_uname('m'),
    'php' => PHP_VERSION,
    'note' => 'In-process Worker plus InMemoryBridge. This does not include Eregion or a network hop.',
    'rounds' => $rounds,
    'requests_per_round' => $requests,
    'median_rps' => ['off' => $offRps, 'observe' => $observeRps],
    'observe_throughput_drop_percent' => $delta,
    'last_observe_stats' => $samples['observe'][$rounds - 1]['stats'],
    'samples' => $samples,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
