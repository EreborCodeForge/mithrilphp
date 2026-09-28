<?php

declare(strict_types=1);

namespace Erebor\Mithril\Jobs;

/**
 * Result of a single transport poll: a job, temporary idle, or permanent stop.
 */
final readonly class JobPollResult
{
    private function __construct(
        public ?JobEnvelope $job,
        public bool $idle,
        public bool $stop,
    ) {}

    public static function job(JobEnvelope $job): self
    {
        return new self($job, false, false);
    }

    public static function idle(): self
    {
        return new self(null, true, false);
    }

    public static function stop(): self
    {
        return new self(null, false, true);
    }

    public function isJob(): bool
    {
        return $this->job !== null;
    }

    public function isIdle(): bool
    {
        return $this->idle;
    }

    public function isStop(): bool
    {
        return $this->stop;
    }
}
