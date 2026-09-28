<?php

declare(strict_types=1);

namespace Erebor\Mithril\Jobs;

/**
 * Adapts a next()-based transport to the poll() contract.
 * null from next() maps to JobPollResult::stop().
 */
final class LegacyJobTransportAdapter implements JobTransport
{
    public function __construct(
        private readonly LegacyJobTransport $legacy,
    ) {}

    public function poll(): JobPollResult
    {
        $job = $this->legacy->next();
        if ($job === null) {
            return JobPollResult::stop();
        }

        return JobPollResult::job($job);
    }

    public function ack(JobEnvelope $job): void
    {
        $this->legacy->ack($job);
    }

    public function retry(JobEnvelope $job, JobResult $result): void
    {
        $this->legacy->retry($job, $result);
    }

    public function reject(JobEnvelope $job, JobResult $result): void
    {
        $this->legacy->reject($job, $result);
    }
}
