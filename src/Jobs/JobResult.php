<?php

declare(strict_types=1);

namespace Erebor\Mithril\Jobs;

final readonly class JobResult
{
    public function __construct(
        public JobOutcome $outcome,
        public ?string $reason = null,
        public ?int $delayMs = null,
    ) {}

    public static function ack(): self
    {
        return new self(JobOutcome::Ack);
    }

    public static function retry(?string $reason = null, ?int $delayMs = null): self
    {
        return new self(JobOutcome::Retry, $reason, $delayMs);
    }

    public static function reject(?string $reason = null): self
    {
        return new self(JobOutcome::Reject, $reason);
    }
}
