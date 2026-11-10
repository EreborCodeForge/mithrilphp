<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Guard;

interface GuardProbe
{
    public function id(): string;

    public function before(GuardCycleContext $context): void;

    /**
     * @return list<GuardFinding>
     */
    public function after(GuardCycleContext $context): array;

    /**
     * covered, partial, skipped or not_configured.
     */
    public function coverage(): string;
}
