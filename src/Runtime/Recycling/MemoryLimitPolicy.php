<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Recycling;

final class MemoryLimitPolicy implements RecyclingPolicy
{
    public function __construct(
        private readonly int $memoryLimitBytes,
    ) {}

    public function evaluate(WorkerContext $context): RecyclingDecision
    {
        if ($this->memoryLimitBytes <= 0) {
            return RecyclingDecision::keep();
        }

        if ($context->memoryUsage >= $this->memoryLimitBytes) {
            return RecyclingDecision::recycle('memory_limit');
        }

        return RecyclingDecision::keep();
    }
}
