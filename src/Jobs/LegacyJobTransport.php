<?php

declare(strict_types=1);

namespace Erebor\Mithril\Jobs;

/**
 * Pre-poll JobTransport shape: null from next() means stop.
 *
 * @deprecated Implement JobTransport::poll() instead.
 */
interface LegacyJobTransport
{
    public function next(): ?JobEnvelope;

    public function ack(JobEnvelope $job): void;

    public function retry(JobEnvelope $job, JobResult $result): void;

    public function reject(JobEnvelope $job, JobResult $result): void;
}
