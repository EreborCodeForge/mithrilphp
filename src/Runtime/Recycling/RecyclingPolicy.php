<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Recycling;

interface RecyclingPolicy
{
    public function evaluate(WorkerContext $context): RecyclingDecision;
}
