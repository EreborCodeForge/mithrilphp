<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Recycling;

final readonly class WorkerContext
{
    public function __construct(
        public int $requestsHandled,
        public int $memoryUsage,
        public int $memoryPeak,
        public mixed $lastError = null,
    ) {}
}
