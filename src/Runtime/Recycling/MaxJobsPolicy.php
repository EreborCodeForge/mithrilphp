<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Recycling;

final class MaxJobsPolicy implements RecyclingPolicy
{
    public function __construct(
        private readonly int $maxJobs,
    ) {}

    public function evaluate(WorkerContext $context): RecyclingDecision
    {
        if ($this->maxJobs <= 0) {
            return RecyclingDecision::keep();
        }

        if ($context->requestsHandled >= $this->maxJobs) {
            return RecyclingDecision::recycle('max_jobs');
        }

        return RecyclingDecision::keep();
    }
}
