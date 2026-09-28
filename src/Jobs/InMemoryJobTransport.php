<?php

declare(strict_types=1);

namespace Erebor\Mithril\Jobs;

/**
 * FIFO in-memory queue for tests and demos. Retry requeues immediately (delayMs is a hint only).
 *
 * When idleWhenEmpty is false (default), an empty queue returns stop — suitable for finite test runs.
 * When true, an empty queue returns idle — suitable for persistent consumers.
 */
final class InMemoryJobTransport implements InterruptibleJobTransport
{
    /** @var list<JobEnvelope> */
    private array $queue;

    /** @var list<string> */
    private array $acked = [];

    /** @var list<string> */
    private array $rejected = [];

    private bool $stopped = false;

    /**
     * @param list<JobEnvelope> $jobs
     */
    public function __construct(
        array $jobs = [],
        private readonly bool $idleWhenEmpty = false,
    ) {
        $this->queue = array_values($jobs);
    }

    public function push(JobEnvelope $job): void
    {
        $this->queue[] = $job;
    }

    public function poll(): JobPollResult
    {
        if ($this->stopped) {
            return JobPollResult::stop();
        }

        if ($this->queue === []) {
            return $this->idleWhenEmpty ? JobPollResult::idle() : JobPollResult::stop();
        }

        $job = array_shift($this->queue);

        return JobPollResult::job($job);
    }

    public function stop(): void
    {
        $this->stopped = true;
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
