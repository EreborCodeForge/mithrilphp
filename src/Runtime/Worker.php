<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime;

use Erebor\Mithril\Contracts\HttpApplication;
use Erebor\Mithril\Http\Response;
use Erebor\Mithril\Runtime\Eregion\Exceptions\ProtocolException;
use Erebor\Mithril\Runtime\Guard\GuardCycleContext;
use Erebor\Mithril\Runtime\Guard\GuardDecision;
use Erebor\Mithril\Runtime\Guard\NullWorkerGuard;
use Erebor\Mithril\Runtime\Guard\Policy\RecyclingCombiner;
use Erebor\Mithril\Runtime\Guard\ProbeWorkerGuard;
use Erebor\Mithril\Runtime\Guard\WorkerGuard;
use Erebor\Mithril\Runtime\Recycling\CompositeRecyclingPolicy;
use Erebor\Mithril\Runtime\Recycling\MaxRequestsPolicy;
use Erebor\Mithril\Runtime\Recycling\RecyclingPolicy;
use Erebor\Mithril\Runtime\Recycling\WorkerContext;
use Throwable;

/**
 * Long-running HTTP loop: boot once, then scope → handle → respond per request.
 * Pair with a compiled container for maximum efficiency.
 */
final class Worker
{
    private readonly RecyclingPolicy $recyclingPolicy;

    private readonly WorkerGuard $guard;

    public function __construct(
        private readonly HttpApplication $app,
        private readonly RequestBridge $bridge,
        private readonly int $maxRequests = 0,
        ?RecyclingPolicy $recyclingPolicy = null,
        private readonly int $memoryLimitBytes = 0,
        ?WorkerGuard $guard = null,
    ) {
        $this->recyclingPolicy = $recyclingPolicy ?? new CompositeRecyclingPolicy(
            new MaxRequestsPolicy($maxRequests),
            new \Erebor\Mithril\Runtime\Recycling\MemoryLimitPolicy($memoryLimitBytes),
        );
        $this->guard = $guard ?? new NullWorkerGuard();
    }

    /**
     * @return int Number of requests served
     */
    public function run(): int
    {
        return $this->runResult()->requestsHandled;
    }

    public function runResult(): WorkerResult
    {
        try {
            $this->app->boot();
        } catch (Throwable $e) {
            return new WorkerResult(0, WorkerStopReason::BootstrapFailure);
        }

        $container = $this->app->getContainer();
        if ($this->guard instanceof ProbeWorkerGuard) {
            $this->guard->attachContainer($container);
        }

        $served = 0;
        $stopReason = WorkerStopReason::Stopped;
        $recycleReason = null;
        $generation = $this->workerGeneration();
        $combiner = new RecyclingCombiner();

        while (true) {
            try {
                $request = $this->bridge->next();
            } catch (ProtocolException) {
                return new WorkerResult($served, WorkerStopReason::ProtocolFailure, $recycleReason);
            } catch (Throwable) {
                return new WorkerResult($served, WorkerStopReason::ProtocolFailure, $recycleReason);
            }

            if ($request === null) {
                $stopReason = $served > 0 && $recycleReason !== null
                    ? WorkerStopReason::Recycled
                    : WorkerStopReason::Stopped;
                break;
            }

            $response = null;
            $scopeFailed = false;
            $beforeOk = false;
            $cycle = null;

            $container->beginScope();
            try {
                try {
                    if ($this->guard->enabled()) {
                        $cycle = new GuardCycleContext(
                            workerGeneration: $generation,
                            requestOrdinal: $served + 1,
                            mode: $this->guard->mode(),
                            correlationId: 'c' . ($served + 1),
                            monotonicNs: hrtime(true),
                        );
                        try {
                            $this->guard->before($cycle);
                            $beforeOk = true;
                        } catch (Throwable) {
                            $beforeOk = false;
                        }
                    }
                    $response = $this->app->handle($request);
                } catch (Throwable) {
                    $response = Response::json(['error' => 'Internal Server Error'], 500);
                }
            } finally {
                try {
                    $container->endScope();
                } catch (Throwable) {
                    $scopeFailed = true;
                }
            }

            $guardDecision = GuardDecision::none();
            if ($this->guard->enabled() && $cycle !== null) {
                if (!$beforeOk) {
                    $cycle = $cycle->withIncompleteCollection();
                }
                try {
                    $guardDecision = $this->guard->after($cycle);
                } catch (Throwable) {
                    $guardDecision = GuardDecision::none();
                }
            }
            $cycle = null;

            $served++;
            $memory = memory_get_usage(true);
            $peak = memory_get_peak_usage(true);

            $decision = $this->recyclingPolicy->evaluate(new WorkerContext(
                requestsHandled: $served,
                memoryUsage: $memory,
                memoryPeak: $peak,
            ));

            $combined = $combiner->combine($decision, $guardDecision, $scopeFailed);
            $shouldRecycle = $combined->shouldRecycle;
            $reason = $combined->reason;

            $metadata = new WorkerMetadata(
                requestsHandled: $served,
                memoryUsage: $memory,
                memoryPeak: $peak,
                recycle: $shouldRecycle,
                recycleReason: $shouldRecycle ? $reason : null,
            );

            try {
                if ($this->bridge instanceof WorkerMetadataAwareBridge) {
                    $this->bridge->respond($response, $metadata);
                } else {
                    $this->bridge->respond($response);
                }
            } catch (ProtocolException) {
                return new WorkerResult($served, WorkerStopReason::ProtocolFailure, $reason);
            } catch (Throwable) {
                return new WorkerResult($served, WorkerStopReason::ProtocolFailure, $reason);
            }

            if ($scopeFailed) {
                return new WorkerResult($served, WorkerStopReason::ScopeCleanupFailure, 'scope_cleanup_failure');
            }

            if ($shouldRecycle) {
                return new WorkerResult($served, WorkerStopReason::Recycled, $reason);
            }
        }

        return new WorkerResult($served, $stopReason, $recycleReason);
    }

    private function workerGeneration(): int
    {
        $raw = EregionWorkloadMetadata::fromEnvironment()->generation;
        if ($raw === null || preg_match('/^\d+$/', $raw) !== 1) {
            return 0;
        }

        return (int) $raw;
    }
}
