<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime;

final readonly class WorkerResult
{
    public function __construct(
        public int $requestsHandled,
        public WorkerStopReason $stopReason,
        public ?string $recycleReason = null,
    ) {}

    public function exitCode(): WorkerExitCode
    {
        return WorkerExitCode::fromStopReason($this->stopReason);
    }
}
