<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime;

use Erebor\Mithril\Jobs\JobEnvelope;
use Erebor\Mithril\Jobs\JobResult;

/**
 * Optional hooks for job-worker observability. Default implementation is no-op.
 */
interface JobWorkerObserver
{
    public function onBoot(): void;

    public function onIdle(): void;

    public function onJobStarted(JobEnvelope $job): void;

    public function onJobFinished(JobEnvelope $job, JobResult $result, float $duration): void;

    public function onRetry(JobEnvelope $job): void;

    public function onReject(JobEnvelope $job): void;

    public function onRecycle(string $reason): void;
}
