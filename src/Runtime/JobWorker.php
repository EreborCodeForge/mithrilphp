<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime;

use Erebor\Mithril\Container;
use Erebor\Mithril\Contracts\JobApplication;
use Erebor\Mithril\Jobs\InterruptibleJobTransport;
use Erebor\Mithril\Jobs\JobEnvelope;
use Erebor\Mithril\Jobs\JobOutcome;
use Erebor\Mithril\Jobs\JobResult;
use Erebor\Mithril\Jobs\JobTransport;
use Erebor\Mithril\Runtime\Recycling\CompositeRecyclingPolicy;
use Erebor\Mithril\Runtime\Recycling\MaxJobsPolicy;
use Erebor\Mithril\Runtime\Recycling\MaxUptimePolicy;
use Erebor\Mithril\Runtime\Recycling\MemoryLimitPolicy;
use Erebor\Mithril\Runtime\Recycling\RecyclingPolicy;
use Erebor\Mithril\Runtime\Recycling\WorkerContext;
use RuntimeException;
use Throwable;

/**
 * Persistent job loop: boot once, then poll → scope → handle → ack|retry|reject per job.
 * Idle does not stop the process. SIGTERM drains gracefully. No Eregion dependency.
 */
final class JobWorker
{
    private readonly ?RecyclingPolicy $recyclingPolicyOverride;
    private readonly JobWorkerObserver $observer;
    private readonly int $maxJobs;
    private readonly int $memoryLimitBytes;
    private readonly int $maxUptimeSeconds;
    private JobWorkerState $state = JobWorkerState::Starting;
    private bool $draining = false;
    private ?JobTransport $activeTransport = null;

    public function __construct(
        private readonly JobApplication $app,
        private readonly ?JobTransport $transport = null,
        int $maxJobs = 0,
        ?RecyclingPolicy $recyclingPolicy = null,
        int $memoryLimitBytes = 0,
        int $maxUptimeSeconds = 0,
        ?JobWorkerObserver $observer = null,
        private readonly ?EregionWorkloadMetadata $eregionMetadata = null,
    ) {
        $this->recyclingPolicyOverride = $recyclingPolicy;
        $this->maxJobs = $maxJobs;
        $this->memoryLimitBytes = $memoryLimitBytes;
        $this->maxUptimeSeconds = $maxUptimeSeconds;
        $this->observer = $observer ?? new NullJobWorkerObserver();
    }

    public function eregionMetadata(): ?EregionWorkloadMetadata
    {
        return $this->eregionMetadata;
    }

    public function state(): JobWorkerState
    {
        return $this->state;
    }

    /**
     * Request graceful drain: finish the current job (if any), do not poll for new work.
     */
    public function drain(): void
    {
        $this->requestDrain();
    }

    /**
     * @return int Number of jobs handled
     */
    public function run(): int
    {
        return $this->runResult()->requestsHandled;
    }

    public function runResult(): WorkerResult
    {
        $this->state = JobWorkerState::Starting;

        try {
            $this->app->boot();
        } catch (Throwable) {
            $this->state = JobWorkerState::Failed;

            return new WorkerResult(0, WorkerStopReason::BootstrapFailure);
        }

        $container = $this->app->getContainer();

        try {
            $this->activeTransport = $this->transport ?? $this->resolveTransportFromContainer($container);
        } catch (Throwable) {
            $this->state = JobWorkerState::Failed;

            return new WorkerResult(0, WorkerStopReason::BootstrapFailure);
        }

        $recyclingPolicy = $this->resolveRecyclingPolicy($container);
        $observer = $this->resolveObserver($container);

        $observer->onBoot();
        $this->installSignalHandlers();

        $transport = $this->activeTransport;
        $served = 0;
        $this->state = JobWorkerState::Idle;

        while (!$this->draining) {
            try {
                $poll = $transport->poll();
            } catch (Throwable) {
                $this->state = JobWorkerState::Failed;

                return new WorkerResult($served, WorkerStopReason::TransportFailure);
            }

            if ($poll->isStop()) {
                $this->state = JobWorkerState::Stopped;

                return new WorkerResult(
                    $served,
                    $this->draining ? WorkerStopReason::Drained : WorkerStopReason::Stopped,
                );
            }

            if ($poll->isIdle()) {
                $this->state = JobWorkerState::Idle;
                $observer->onIdle();
                continue;
            }

            /** @var JobEnvelope $job */
            $job = $poll->job;
            $this->state = JobWorkerState::Busy;
            $observer->onJobStarted($job);

            $scopeFailed = false;
            $startedAt = microtime(true);
            $result = JobResult::ack();

            $container->beginScope();
            try {
                try {
                    $result = $this->app->handle($job);
                } catch (Throwable $e) {
                    $result = JobResult::retry($e->getMessage() !== '' ? $e->getMessage() : $e::class);
                }

                $this->applyResult($transport, $observer, $job, $result);
            } finally {
                try {
                    $container->endScope();
                } catch (Throwable) {
                    $scopeFailed = true;
                }
            }

            $duration = microtime(true) - $startedAt;
            $observer->onJobFinished($job, $result, $duration);
            $served++;

            if ($scopeFailed) {
                $this->state = JobWorkerState::Failed;

                return new WorkerResult($served, WorkerStopReason::ScopeCleanupFailure, 'scope_cleanup_failure');
            }

            $memory = memory_get_usage(true);
            $peak = memory_get_peak_usage(true);
            $decision = $recyclingPolicy->evaluate(new WorkerContext(
                requestsHandled: $served,
                memoryUsage: $memory,
                memoryPeak: $peak,
            ));

            if ($decision->shouldRecycle) {
                $reason = $decision->reason ?? 'recycle';
                $observer->onRecycle($reason);
                $this->state = JobWorkerState::Stopped;

                return new WorkerResult($served, WorkerStopReason::Recycled, $reason);
            }

            if ($this->draining) {
                break;
            }

            $this->state = JobWorkerState::Idle;
        }

        $this->state = JobWorkerState::Stopped;

        return new WorkerResult($served, WorkerStopReason::Drained);
    }

    private function applyResult(
        JobTransport $transport,
        JobWorkerObserver $observer,
        JobEnvelope $job,
        JobResult $result,
    ): void {
        match ($result->outcome) {
            JobOutcome::Ack => $transport->ack($job),
            JobOutcome::Retry => $this->notifyRetry($transport, $observer, $job, $result),
            JobOutcome::Reject => $this->notifyReject($transport, $observer, $job, $result),
        };
    }

    private function notifyRetry(
        JobTransport $transport,
        JobWorkerObserver $observer,
        JobEnvelope $job,
        JobResult $result,
    ): void {
        $transport->retry($job, $result);
        $observer->onRetry($job);
    }

    private function notifyReject(
        JobTransport $transport,
        JobWorkerObserver $observer,
        JobEnvelope $job,
        JobResult $result,
    ): void {
        $transport->reject($job, $result);
        $observer->onReject($job);
    }

    private function resolveTransportFromContainer(Container $container): JobTransport
    {
        if (!$container->has(JobTransport::class)) {
            throw new RuntimeException(
                'JobTransport is not bound in the container. Bind ' . JobTransport::class . ' after boot.'
            );
        }

        $transport = $container->get(JobTransport::class);
        if (!$transport instanceof JobTransport) {
            throw new RuntimeException(
                'Container binding for JobTransport must resolve to ' . JobTransport::class
            );
        }

        return $transport;
    }

    private function resolveRecyclingPolicy(Container $container): RecyclingPolicy
    {
        if ($this->recyclingPolicyOverride !== null) {
            return $this->recyclingPolicyOverride;
        }

        if ($container->has(RecyclingPolicy::class)) {
            $bound = $container->get(RecyclingPolicy::class);
            if ($bound instanceof RecyclingPolicy) {
                return $bound;
            }
        }

        return new CompositeRecyclingPolicy(
            new MaxJobsPolicy($this->maxJobs),
            new MemoryLimitPolicy($this->memoryLimitBytes),
            new MaxUptimePolicy($this->maxUptimeSeconds),
        );
    }

    private function resolveObserver(Container $container): JobWorkerObserver
    {
        if (!$this->observer instanceof NullJobWorkerObserver) {
            return $this->observer;
        }

        if ($container->has(JobWorkerObserver::class)) {
            $bound = $container->get(JobWorkerObserver::class);
            if ($bound instanceof JobWorkerObserver) {
                return $bound;
            }
        }

        return $this->observer;
    }

    private function requestDrain(): void
    {
        $this->draining = true;
        $this->state = JobWorkerState::Draining;
        $transport = $this->activeTransport ?? $this->transport;
        if ($transport instanceof InterruptibleJobTransport) {
            $transport->stop();
        }
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void {
            $this->requestDrain();
        });
        if (defined('SIGINT')) {
            pcntl_signal(SIGINT, function (): void {
                $this->requestDrain();
            });
        }
    }
}
