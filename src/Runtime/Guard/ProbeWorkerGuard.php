<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Guard;

use Erebor\Mithril\Container;
use Erebor\Mithril\Runtime\Guard\Policy\GuardPolicy;
use Erebor\Mithril\Runtime\Guard\Probe\ScopedContainerProbe;
use Erebor\Mithril\Runtime\Guard\Report\GuardReport;
use Throwable;

final class ProbeWorkerGuard implements WorkerGuard
{
    /** @var list<GuardProbe> */
    private array $probes;

    private bool $open = false;

    /** @var array<string, bool> */
    private array $beforeOk = [];

    /** @var list<GuardFinding> */
    private array $pendingErrors = [];

    /**
     * One exemplar per diagnostic. Counters in $findingsByCode stay cumulative.
     *
     * @var array<string, GuardFinding>
     */
    private array $retainedFindings = [];

    private int $cycles = 0;

    private int $probesExecuted = 0;

    private int $probeOk = 0;

    private int $probeErrors = 0;

    private int $hookDurationNs = 0;

    /** @var array<string, int> */
    private array $probeDurationNs = [];

    /** @var array<string, int> */
    private array $findingsByCode = [
        GuardVocabulary::STATE_RETAINED => 0,
        GuardVocabulary::PROBE_ERROR => 0,
        GuardVocabulary::MEMORY_TREND => 0,
    ];

    private ?GuardEventLog $eventLog = null;

    /**
     * @param list<GuardProbe> $probes
     */
    public function __construct(
        private readonly GuardMode $mode,
        array $probes,
        private readonly GuardPolicy $policy,
        int $maxProbesPerCycle = 8,
        private readonly int $sampleEveryNRequests = 1,
    ) {
        $limit = max(0, $maxProbesPerCycle);
        $this->probes = array_slice(array_values($probes), 0, $limit);
    }

    public function enabled(): bool
    {
        return $this->mode->runsProbes();
    }

    public function mode(): GuardMode
    {
        return $this->mode;
    }

    public function setEventLog(?GuardEventLog $eventLog): void
    {
        $this->eventLog = $eventLog;
    }

    public function attachContainer(Container $container): void
    {
        foreach ($this->probes as $probe) {
            if ($probe instanceof ScopedContainerProbe) {
                $probe->attach($container);
            }
        }
    }

    public function prepare(): void
    {
        foreach ($this->probes as $probe) {
            $probe->prepare();
        }
    }

    public function before(GuardCycleContext $context): void
    {
        if (!$this->enabled()) {
            return;
        }

        $started = hrtime(true);
        try {
            if ($this->open) {
                $this->pendingErrors[] = GuardFinding::reentrancy();
                $this->probeErrors++;

                return;
            }

            $this->open = true;
            $this->beforeOk = [];
            if ($this->probes === []) {
                return;
            }
            foreach ($this->probes as $probe) {
                if (!$this->accepts($probe, $context->requestOrdinal)) {
                    continue;
                }
                try {
                    $probe->before($context);
                    $this->beforeOk[$probe->id()] = true;
                    $this->probeOk++;
                    $this->probesExecuted++;
                } catch (Throwable) {
                    $this->beforeOk[$probe->id()] = false;
                    $this->probeErrors++;
                    $this->pendingErrors[] = GuardFinding::probeError($probe->id());
                }
            }
        } finally {
            $this->hookDurationNs += hrtime(true) - $started;
        }
    }

    public function after(GuardCycleContext $context): GuardDecision
    {
        if (!$this->enabled()) {
            return GuardDecision::none();
        }

        $started = hrtime(true);
        try {
            $findings = $this->pendingErrors;
            $this->pendingErrors = [];

            if ($context->collectionIncomplete || !$this->open) {
                $this->open = false;
                $this->beforeOk = [];
                $findings[] = GuardFinding::incomplete();
                $this->probeErrors++;
                $decision = $this->decide($findings);
                $this->remember($decision, $context->requestOrdinal);

                return $decision;
            }

            foreach ($this->probes as $probe) {
                if (($this->beforeOk[$probe->id()] ?? false) !== true) {
                    continue;
                }
                try {
                    foreach ($probe->after($context) as $finding) {
                        $findings[] = $finding;
                    }
                    $this->probeOk++;
                    $this->probesExecuted++;
                } catch (Throwable) {
                    $this->probeErrors++;
                    $findings[] = GuardFinding::probeError($probe->id());
                }
            }

            $this->open = false;
            $this->beforeOk = [];
            $decision = $this->decide($findings);
            $this->remember($decision, $context->requestOrdinal);

            return $decision;
        } finally {
            $this->hookDurationNs += hrtime(true) - $started;
        }
    }

    public function report(): GuardReport
    {
        $coverage = [];
        foreach ($this->probes as $probe) {
            $status = $probe->coverage();
            if (!in_array($status, ['covered', 'partial', 'skipped', 'not_configured'], true)) {
                $status = 'skipped';
            }
            $coverage[] = ['id' => GuardFinding::safeProbeId($probe->id()), 'status' => $status];
        }

        return GuardReport::fromFindings(
            $this->mode,
            $this->cycles,
            $this->probesExecuted,
            array_values($this->retainedFindings),
            $coverage,
            $this->probeErrors,
        );
    }

    /**
     * @return array{
     *     probe_ok: int,
     *     probe_error: int,
     *     hook_duration_ns: int,
     *     inspections: int,
     *     findings_by_code: array<string, int>,
     *     probe_duration_ns: array<string, int>
     * }
     */
    public function stats(): array
    {
        return [
            'probe_ok' => $this->probeOk,
            'probe_error' => $this->probeErrors,
            'hook_duration_ns' => $this->hookDurationNs,
            'inspections' => $this->probesExecuted,
            'findings_by_code' => $this->findingsByCode,
            'probe_duration_ns' => $this->probeDurationNs,
        ];
    }

    private function accepts(GuardProbe $probe, int $ordinal): bool
    {
        if ($this->mode === GuardMode::Audit || !$probe->sampleable()) {
            return true;
        }

        $n = $this->sampleEveryNRequests;
        if ($n <= 1) {
            return true;
        }

        return $ordinal > 0 && $ordinal % $n === 0;
    }

    private function remember(GuardDecision $decision, int $requestOrdinal): void
    {
        $this->cycles++;
        $action = $decision->recycle ? 'recycle_after_response' : 'log';
        foreach ($decision->findings as $finding) {
            if (isset($this->findingsByCode[$finding->code])) {
                $this->findingsByCode[$finding->code]++;
            }
            $key = $finding->code
                . "\0" . $finding->probeId
                . "\0" . $finding->severity->value
                . "\0" . $finding->confidence->value
                . "\0" . $finding->safeSummary;
            $this->retainedFindings[$key] = $finding;
            $this->eventLog?->finding($this->mode, $finding, $requestOrdinal, $action);
        }
    }

    /**
     * @param list<GuardFinding> $findings
     */
    private function decide(array $findings): GuardDecision
    {
        if ($findings === []) {
            return GuardDecision::none();
        }

        return $this->policy->decide($this->sanitize($findings), $this->mode);
    }

    /**
     * @param list<GuardFinding> $findings
     * @return list<GuardFinding>
     */
    private function sanitize(array $findings): array
    {
        $safe = [];
        foreach ($findings as $finding) {
            if (
                !GuardVocabulary::isFindingCode($finding->code)
                || !GuardVocabulary::isSummary($finding->safeSummary)
                || !GuardVocabulary::isRecommendation($finding->recommendation)
                || $finding->scope !== 'http'
            ) {
                $safe[] = GuardFinding::probeError($finding->probeId);
                $this->probeErrors++;
                continue;
            }

            $safe[] = new GuardFinding(
                $finding->code,
                GuardFinding::safeProbeId($finding->probeId),
                $finding->severity,
                $finding->confidence,
                $finding->safeSummary,
                $finding->recommendation,
            );
        }

        return $safe;
    }
}
