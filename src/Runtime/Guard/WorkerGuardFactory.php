<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Guard;

use Erebor\Mithril\Runtime\Guard\Policy\GuardPolicy;
use Erebor\Mithril\Runtime\Guard\Probe\ExplicitStaticStateProbe;
use Erebor\Mithril\Runtime\Guard\Probe\MemoryTrendProbe;
use Erebor\Mithril\Runtime\Guard\Probe\ScopedContainerProbe;

final class WorkerGuardFactory
{
    public static function fromConfig(GuardConfig $config): WorkerGuard
    {
        if (!$config->mode->runsProbes()) {
            return new NullWorkerGuard();
        }

        $probes = [];
        if ($config->enableScopedProbe) {
            $probes[] = new ScopedContainerProbe();
        }
        if ($config->enableStaticProbe) {
            $probes[] = new ExplicitStaticStateProbe($config->watchpoints);
        }
        if ($config->enableMemoryProbe) {
            $probes[] = new MemoryTrendProbe($config->sampleEveryNRequests);
        }

        return new ProbeWorkerGuard(
            $config->mode,
            $probes,
            new GuardPolicy($config->recycleOnVerifiedStateViolation),
            $config->maxProbesPerCycle,
        );
    }
}
