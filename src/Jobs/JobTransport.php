<?php

declare(strict_types=1);

namespace Erebor\Mithril\Jobs;

/**
 * Source of jobs for JobWorker. Brokers live in app adapters; core only owns the contract.
 */
interface JobTransport
{
    /** Next job, or null when the transport signals idle/stop. */
    public function next(): ?JobEnvelope;

    public function ack(JobEnvelope $job): void;

    public function retry(JobEnvelope $job, JobResult $result): void;

    public function reject(JobEnvelope $job, JobResult $result): void;
}
