<?php

declare(strict_types=1);

namespace Erebor\Mithril\Jobs;

/**
 * FIFO in-memory queue for tests and demos. Retry requeues immediately (delayMs is a hint only).
 */
final class InMemoryJobTransport implements JobTransport
{
    /** @var list<JobEnvelope> */
    private array $queue;

    /** @var list<string> */
    private array $acked = [];

    /** @var list<string> */
    private array $rejected = [];

    /**
     * @param list<JobEnvelope> $jobs
     */
    public function __construct(array $jobs = [])
    {
        $this->queue = array_values($jobs);
    }

    public function push(JobEnvelope $job): void
    {
        $this->queue[] = $job;
    }

    public function next(): ?JobEnvelope
    {
        if ($this->queue === []) {
            return null;
        }

        return array_shift($this->queue);
    }

    public function ack(JobEnvelope $job): void
    {
        $this->acked[] = $job->id;
    }

    public function retry(JobEnvelope $job, JobResult $result): void
    {
        $this->queue[] = new JobEnvelope(
            id: $job->id,
            name: $job->name,
            payload: $job->payload,
            attempt: $job->attempt + 1,
            headers: $job->headers,
        );
    }

    public function reject(JobEnvelope $job, JobResult $result): void
    {
        $this->rejected[] = $job->id;
    }

    /**
     * @return list<JobEnvelope>
     */
    public function pending(): array
    {
        return $this->queue;
    }

    /**
     * @return list<string>
     */
    public function ackedIds(): array
    {
        return $this->acked;
    }

    /**
     * @return list<string>
     */
    public function rejectedIds(): array
    {
        return $this->rejected;
    }
}
