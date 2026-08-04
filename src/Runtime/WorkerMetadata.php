<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime;

final readonly class WorkerMetadata
{
    public function __construct(
        public int $requestsHandled,
        public int $memoryUsage,
        public int $memoryPeak,
        public bool $recycle = false,
        public ?string $recycleReason = null,
    ) {}
}
