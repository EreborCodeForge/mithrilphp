<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Guard\Policy;

use Erebor\Mithril\Runtime\Guard\GuardDecision;
use Erebor\Mithril\Runtime\Guard\GuardVocabulary;
use Erebor\Mithril\Runtime\Recycling\RecyclingDecision;

/**
 * scope_cleanup_failure wins. An already decided policy reason is kept.
 * Guard recycle applies only when the existing policy says keep.
 */
final class RecyclingCombiner
{
    public function combine(
        RecyclingDecision $policy,
        GuardDecision $guard,
        bool $scopeFailed,
    ): RecyclingDecision {
        if ($scopeFailed) {
            return RecyclingDecision::recycle('scope_cleanup_failure');
        }

        if ($policy->shouldRecycle) {
            return $policy;
        }

        if ($guard->recycle && GuardVocabulary::isGuardRecycleReason($guard->recycleReason)) {
            return RecyclingDecision::recycle((string) $guard->recycleReason);
        }

        return RecyclingDecision::keep();
    }
}
