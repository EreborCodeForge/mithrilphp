<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Guard\Policy;

use Erebor\Mithril\Runtime\Guard\FindingConfidence;
use Erebor\Mithril\Runtime\Guard\FindingSeverity;
use Erebor\Mithril\Runtime\Guard\GuardDecision;
use Erebor\Mithril\Runtime\Guard\GuardFinding;
use Erebor\Mithril\Runtime\Guard\GuardMode;
use Erebor\Mithril\Runtime\Guard\GuardVocabulary;

final class GuardPolicy
{
    public function __construct(
        private readonly bool $recycleOnVerifiedStateViolation,
    ) {}

    /**
     * @param list<GuardFinding> $findings
     */
    public function decide(array $findings, GuardMode $mode): GuardDecision
    {
        $findings = $this->sort($findings);
        if ($findings === []) {
            return GuardDecision::none();
        }

        $recycle = false;
        $reason = null;

        if ($mode === GuardMode::Enforce && $this->recycleOnVerifiedStateViolation) {
            foreach ($findings as $finding) {
                if (
                    $finding->severity === FindingSeverity::Critical
                    && $finding->confidence === FindingConfidence::Verified
                    && $finding->code === GuardVocabulary::STATE_RETAINED
                ) {
                    $recycle = true;
                    $reason = GuardVocabulary::REASON_STATE;
                    break;
                }
            }
        }

        return new GuardDecision($findings, $recycle, $reason);
    }

    /**
     * @param list<GuardFinding> $findings
     * @return list<GuardFinding>
     */
    private function sort(array $findings): array
    {
        usort($findings, static function (GuardFinding $a, GuardFinding $b): int {
            return [$a->probeId, $a->code, $a->safeSummary] <=> [$b->probeId, $b->code, $b->safeSummary];
        });

        return $findings;
    }
}
