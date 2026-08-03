<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime;

use Erebor\Mithril\Contracts\HttpApplication;
use Erebor\Mithril\Http\Response;
use Erebor\Mithril\Runtime\Eregion\Exceptions\ProtocolException;
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

    public function __construct(
        private readonly HttpApplication $app,
        private readonly RequestBridge $bridge,
        private readonly int $maxRequests = 0,
        ?RecyclingPolicy $recyclingPolicy = null,
        private readonly int $memoryLimitBytes = 0,
    ) {
        $this->recyclingPolicy = $recyclingPolicy ?? new CompositeRecyclingPolicy(
            new MaxRequestsPolicy($maxRequests),
            new \Erebor\Mithril\Runtime\Recycling\MemoryLimitPolicy($memoryLimitBytes),
        );
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
        $served = 0;
        $stopReason = WorkerStopReason::Stopped;
        $recycleReason = null;

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

            $container->beginScope();
            try {
                try {
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

            $served++;
            $memory = memory_get_usage(true);
            $peak = memory_get_peak_usage(true);

            $decision = $this->recyclingPolicy->evaluate(new WorkerContext(
                requestsHandled: $served,
                memoryUsage: $memory,
                memoryPeak: $peak,
            ));

            $shouldRecycle = $decision->shouldRecycle || $scopeFailed;
            $reason = $scopeFailed ? 'scope_cleanup_failure' : $decision->reason;

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
}
