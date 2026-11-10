<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Guard;

/**
 * Closed strings for findings and recycle reasons.
 * Dynamic class names, property values and exception text are not part of this set.
 */
final class GuardVocabulary
{
    public const STATE_RETAINED = 'guard_state_retained';

    public const PROBE_ERROR = 'guard_probe_error';

    public const MEMORY_TREND = 'guard_memory_trend';

    public const REASON_STATE = 'guard_state_violation';

    public const REASON_RESOURCE = 'guard_resource_violation';

    public const SUMMARY_STATE = 'A watched request-scoped static property differs from baseline after cleanup';

    public const SUMMARY_STATE_OBSERVED = 'A non-scalar watchpoint type changed; this is not a verified reset violation';

    public const SUMMARY_SCOPE = 'Scoped container registry was not empty after endScope';

    public const SUMMARY_PROBE = 'A guard probe failed and was skipped';

    public const SUMMARY_INCOMPLETE = 'Probe collection was incomplete for this cycle';

    public const SUMMARY_MEMORY = 'Sustained process memory growth was observed after warmup; this is not proof of a leak';

    public const SUMMARY_REENTRANCY = 'Guard probe cycle was re-entered and the nested call was ignored';

    public const RECOMMEND_STATE = 'Use request-scoped context instead of mutable static state';

    public const RECOMMEND_SCOPE = 'Ensure endScope finishes and does not retain scoped instances';

    public const RECOMMEND_PROBE = 'Fix the probe failure and rerun the audit; the HTTP response was not blocked';

    public const RECOMMEND_MEMORY = 'Compare with the existing memory limit policy; do not treat this trend as a confirmed leak';

    public const RECOMMEND_REENTRANCY = 'Do not call the guard reentrantly from a probe';

    public static function isFindingCode(string $code): bool
    {
        return $code === self::STATE_RETAINED
            || $code === self::PROBE_ERROR
            || $code === self::MEMORY_TREND;
    }

    public static function isGuardRecycleReason(?string $reason): bool
    {
        return $reason === self::REASON_STATE || $reason === self::REASON_RESOURCE;
    }

    public static function isSummary(string $summary): bool
    {
        return $summary === self::SUMMARY_STATE
            || $summary === self::SUMMARY_STATE_OBSERVED
            || $summary === self::SUMMARY_SCOPE
            || $summary === self::SUMMARY_PROBE
            || $summary === self::SUMMARY_INCOMPLETE
            || $summary === self::SUMMARY_MEMORY
            || $summary === self::SUMMARY_REENTRANCY;
    }

    public static function isRecommendation(string $recommendation): bool
    {
        return $recommendation === self::RECOMMEND_STATE
            || $recommendation === self::RECOMMEND_SCOPE
            || $recommendation === self::RECOMMEND_PROBE
            || $recommendation === self::RECOMMEND_MEMORY
            || $recommendation === self::RECOMMEND_REENTRANCY;
    }
}
