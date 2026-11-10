<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Guard\Probe;

use Erebor\Mithril\Container;
use Erebor\Mithril\Runtime\Guard\GuardCycleContext;
use Erebor\Mithril\Runtime\Guard\GuardFinding;
use Erebor\Mithril\Runtime\Guard\GuardProbe;

/**
 * Counts scoped instances after endScope. A zero count does not prove that
 * external references were dropped.
 */
final class ScopedContainerProbe implements GuardProbe
{
    private ?Container $container = null;

    private string $coverage = 'skipped';

    public function id(): string
    {
        return 'scoped_container';
    }

    public function attach(Container $container): void
    {
        $this->container = $container;
    }

    public function before(GuardCycleContext $context): void
    {
        if ($this->container === null) {
            throw new \RuntimeException('scoped probe is not attached');
        }
    }

    public function after(GuardCycleContext $context): array
    {
        if ($context->collectionIncomplete || $this->container === null) {
            return [];
        }

        $this->coverage = 'covered';
        if ($this->container->scopedInstanceCount() === 0) {
            return [];
        }

        return [GuardFinding::scopeRetained()];
    }

    public function coverage(): string
    {
        return $this->coverage;
    }

    public function sampleable(): bool
    {
        return false;
    }

    public function prepare(): void
    {
    }
}
