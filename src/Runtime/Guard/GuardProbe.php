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

    /**
     * Sampleable probes cannot produce a recycle verdict.
     * Enforce and audit ignore this flag and still run probes that are not sampleable.
     */
    public function sampleable(): bool;

    /**
     * Resolve reflection and other stable metadata once, after the application boots.
     */
    public function prepare(): void;
}
