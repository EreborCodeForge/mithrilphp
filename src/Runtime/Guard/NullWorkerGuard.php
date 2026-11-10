<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Guard;

final class NullWorkerGuard implements WorkerGuard
{
    public function enabled(): bool
    {
        return false;
    }

    public function mode(): GuardMode
    {
        return GuardMode::Off;
    }

    public function before(GuardCycleContext $context): void
    {
    }

    public function after(GuardCycleContext $context): GuardDecision
    {
        return GuardDecision::none();
    }
}
