<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime;

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
use Throwable;

/**
 * Persistent job loop: boot once, then poll → scope → handle → ack|retry|reject per job.
 * Idle does not stop the process. SIGTERM drains gracefully. No Eregion dependency.
 */
final class JobWorker
{
    private readonly RecyclingPolicy $recyclingPolicy;
    private readonly JobWorkerObserver $observer;
    private JobWorkerState $state = JobWorkerState::Starting;
    private bool $draining = false;

    public function __construct(
        private readonly JobApplication $app,
        private readonly JobTransport $transport,
        int $maxJobs = 0,
        ?RecyclingPolicy $recyclingPolicy = null,
        int $memoryLimitBytes = 0,
        int $maxUptimeSeconds = 0,
        ?JobWorkerObserver $observer = null,
        private readonly ?EregionWorkloadMetadata $eregionMetadata = null,
    ) {
        $this->recyclingPolicy = $recyclingPolicy ?? new CompositeRecyclingPolicy(
            new MaxJobsPolicy($maxJobs),
            new MemoryLimitPolicy($memoryLimitBytes),
            new MaxUptimePolicy($maxUptimeSeconds),
        );
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

        $this->observer->onBoot();
        $this->installSignalHandlers();

        $container = $this->app->getContainer();
        $served = 0;
        $this->state = JobWorkerState::Idle;

        while (!$this->draining) {
            try {
                $poll = $this->transport->poll();
            } catch (Throwable) {
                $this->state = JobWorkerState::Failed;

                return new WorkerResult($served, WorkerStopReason::TransportFailure);
            }

            if ($poll->isStop()) {
                $this->state = JobWorkerState::Stopped;

                return new WorkerResult($served, WorkerStopReason::Stopped);
            }

            if ($poll->isIdle()) {
                $this->state = JobWorkerState::Idle;
                $this->observer->onIdle();
                continue;
            }

            /** @var JobEnvelope $job */
            $job = $poll->job;
            $this->state = JobWorkerState::Busy;
            $this->observer->onJobStarted($job);

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

                $this->applyResult($job, $result);
            } finally {
                try {
                    $container->endScope();
                } catch (Throwable) {
                    $scopeFailed = true;
                }
            }

            $duration = microtime(true) - $startedAt;
            $this->observer->onJobFinished($job, $result, $duration);
            $served++;

            if ($scopeFailed) {
                $this->state = JobWorkerState::Failed;

                return new WorkerResult($served, WorkerStopReason::ScopeCleanupFailure, 'scope_cleanup_failure');
            }

            $memory = memory_get_usage(true);
            $peak = memory_get_peak_usage(true);
            $decision = $this->recyclingPolicy->evaluate(new WorkerContext(
                requestsHandled: $served,
                memoryUsage: $memory,
                memoryPeak: $peak,
            ));

            if ($decision->shouldRecycle) {
                $reason = $decision->reason ?? 'recycle';
                $this->observer->onRecycle($reason);
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

    private function applyResult(JobEnvelope $job, JobResult $result): void
    {
        match ($result->outcome) {
            JobOutcome::Ack => $this->transport->ack($job),
            JobOutcome::Retry => $this->notifyRetry($job, $result),
            JobOutcome::Reject => $this->notifyReject($job, $result),
        };
    }

    private function notifyRetry(JobEnvelope $job, JobResult $result): void
    {
        $this->transport->retry($job, $result);
        $this->observer->onRetry($job);
    }

    private function notifyReject(JobEnvelope $job, JobResult $result): void
    {
        $this->transport->reject($job, $result);
        $this->observer->onReject($job);
    }

    private function requestDrain(): void
    {
        $this->draining = true;
        $this->state = JobWorkerState::Draining;
        if ($this->transport instanceof InterruptibleJobTransport) {
            $this->transport->stop();
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
