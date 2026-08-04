<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Recycling;

final class MaxRequestsPolicy implements RecyclingPolicy
{
    public function __construct(
        private readonly int $maxRequests,
    ) {}

    public function evaluate(WorkerContext $context): RecyclingDecision
    {
        if ($this->maxRequests <= 0) {
            return RecyclingDecision::keep();
        }

        if ($context->requestsHandled >= $this->maxRequests) {
            return RecyclingDecision::recycle('max_requests');
        }

        return RecyclingDecision::keep();
    }
}
