<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime;

use Erebor\Mithril\Contracts\JobApplication;
use Erebor\Mithril\Jobs\JobEnvelope;
use Erebor\Mithril\Jobs\JobOutcome;
use Erebor\Mithril\Jobs\JobResult;
use Erebor\Mithril\Jobs\JobTransport;
use Throwable;

/**
 * Long-running job loop: boot once, then scope → handle → ack|retry|reject per job.
 * No Eregion / HTTP bridge dependency.
 */
final class JobWorker
{
    private bool $stopping = false;

    public function __construct(
        private readonly JobApplication $app,
        private readonly JobTransport $transport,
    ) {}

    /**
     * @return int Number of jobs handled
     */
    public function run(): int
    {
        return $this->runResult()->requestsHandled;
    }

    public function runResult(): WorkerResult
    {
        try {
            $this->app->boot();
        } catch (Throwable) {
            return new WorkerResult(0, WorkerStopReason::BootstrapFailure);
        }

        $this->installSignalHandlers();

        $container = $this->app->getContainer();
        $served = 0;

        while (!$this->stopping) {
            $job = $this->transport->next();
            if ($job === null) {
                break;
            }

            $scopeFailed = false;

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

            $served++;

            if ($scopeFailed) {
                return new WorkerResult($served, WorkerStopReason::ScopeCleanupFailure, 'scope_cleanup_failure');
            }
        }

        return new WorkerResult($served, WorkerStopReason::Stopped);
    }

    private function applyResult(JobEnvelope $job, JobResult $result): void
    {
        match ($result->outcome) {
            JobOutcome::Ack => $this->transport->ack($job),
            JobOutcome::Retry => $this->transport->retry($job, $result),
            JobOutcome::Reject => $this->transport->reject($job, $result),
        };
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void {
            $this->stopping = true;
        });
        if (defined('SIGINT')) {
            pcntl_signal(SIGINT, function (): void {
                $this->stopping = true;
            });
        }
    }
}
