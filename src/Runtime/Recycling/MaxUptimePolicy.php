<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Recycling;

/**
 * Recycles after maxUptimeSeconds of wall time since construction (0 = disabled).
 */
final class MaxUptimePolicy implements RecyclingPolicy
{
    private readonly float $startedAt;

    public function __construct(
        private readonly int $maxUptimeSeconds,
        ?float $startedAt = null,
    ) {
        $this->startedAt = $startedAt ?? microtime(true);
    }

    public function evaluate(WorkerContext $context): RecyclingDecision
    {
        if ($this->maxUptimeSeconds <= 0) {
            return RecyclingDecision::keep();
        }

        $elapsed = microtime(true) - $this->startedAt;
        if ($elapsed >= $this->maxUptimeSeconds) {
            return RecyclingDecision::recycle('max_uptime');
        }

        return RecyclingDecision::keep();
    }
}
