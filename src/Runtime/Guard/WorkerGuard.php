<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Guard;

interface WorkerGuard
{
    public function enabled(): bool;

    public function mode(): GuardMode;

    public function before(GuardCycleContext $context): void;

    public function after(GuardCycleContext $context): GuardDecision;
}
