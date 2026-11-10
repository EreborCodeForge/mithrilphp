<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Guard\Report;

use Erebor\Mithril\Runtime\Guard\FindingSeverity;
use Erebor\Mithril\Runtime\Guard\GuardFinding;
use Erebor\Mithril\Runtime\Guard\GuardMode;
use Erebor\Mithril\Runtime\Guard\GuardVocabulary;

final readonly class GuardReport
{
    /**
     * @param list<GuardFinding> $findings
     * @param list<array{id: string, status: string}> $probeCoverage
     */
    public function __construct(
        public string $result,
        public GuardMode $mode,
        public int $cycles,
        public int $probesExecuted,
        public array $findings,
        public array $probeCoverage,
        public int $probeErrors,
        public string $scope = 'http',
    ) {}

    /**
     * @param list<GuardFinding> $findings
     * @param list<array{id: string, status: string}> $probeCoverage
     */
    public static function fromFindings(
        GuardMode $mode,
        int $cycles,
        int $probesExecuted,
        array $findings,
        array $probeCoverage,
        int $probeErrors,
    ): self {
        $findings = self::sort($findings);
        $coverage = $probeCoverage;
        usort($coverage, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        $warnings = 0;
        $criticals = 0;
        $errors = $probeErrors > 0;
        foreach ($findings as $finding) {
            if ($finding->code === GuardVocabulary::PROBE_ERROR) {
                $errors = true;
            }
            if ($finding->severity === FindingSeverity::Warning) {
                $warnings++;
            }
            if ($finding->severity === FindingSeverity::Critical) {
                $criticals++;
            }
        }

        $result = 'clean';
        if ($errors) {
            $result = 'error';
        } elseif ($warnings + $criticals > 0) {
            $result = 'findings';
        }

        return new self($result, $mode, $cycles, $probesExecuted, $findings, $coverage, $probeErrors);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $warnings = 0;
        $criticals = 0;
        foreach ($this->findings as $finding) {
            if ($finding->severity === FindingSeverity::Warning) {
                $warnings++;
            }
            if ($finding->severity === FindingSeverity::Critical) {
                $criticals++;
            }
        }

        return [
            'schema_version' => 'worker-guard/1',
            'result' => $this->result,
            'runtime' => [
                'engine' => 'mithril',
                'mode' => $this->mode->value,
                'scope' => $this->scope,
            ],
            'summary' => [
                'cycles' => $this->cycles,
                'probes_executed' => $this->probesExecuted,
                'warnings' => $warnings,
                'criticals' => $criticals,
                'probe_errors' => $this->probeErrors,
            ],
            'probe_coverage' => $this->probeCoverage,
            'findings' => array_map(static fn (GuardFinding $finding): array => $finding->toArray(), $this->findings),
        ];
    }

    /**
     * 0 clean, 1 relevant finding, 2 probe or collection failure.
     * Without strict, only critical findings fail. Strict also fails warnings.
     */
    public function auditExitCode(bool $strict): int
    {
        if ($this->result === 'error') {
            return 2;
        }

        foreach ($this->findings as $finding) {
            if ($finding->severity === FindingSeverity::Critical) {
                return 1;
            }
            if ($strict && $finding->severity === FindingSeverity::Warning) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * @param list<GuardFinding> $findings
     * @return list<GuardFinding>
     */
    private static function sort(array $findings): array
    {
        usort($findings, static function (GuardFinding $a, GuardFinding $b): int {
            return [$a->probeId, $a->code, $a->safeSummary] <=> [$b->probeId, $b->code, $b->safeSummary];
        });

        return $findings;
    }
}
