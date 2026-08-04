<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Recycling;

final class CompositeRecyclingPolicy implements RecyclingPolicy
{
    /** @var list<RecyclingPolicy> */
    private array $policies;

    public function __construct(RecyclingPolicy ...$policies)
    {
        $this->policies = array_values($policies);
    }

    public function evaluate(WorkerContext $context): RecyclingDecision
    {
        foreach ($this->policies as $policy) {
            $decision = $policy->evaluate($context);
            if ($decision->shouldRecycle) {
                return $decision;
            }
        }

        return RecyclingDecision::keep();
    }
}
