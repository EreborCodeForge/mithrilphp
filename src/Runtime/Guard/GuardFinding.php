<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Guard;

final readonly class GuardFinding
{
    public function __construct(
        public string $code,
        public string $probeId,
        public FindingSeverity $severity,
        public FindingConfidence $confidence,
        public string $safeSummary,
        public string $recommendation,
        public string $scope = 'http',
    ) {}

    public static function stateRetained(string $probeId): self
    {
        return new self(
            GuardVocabulary::STATE_RETAINED,
            $probeId,
            FindingSeverity::Critical,
            FindingConfidence::Verified,
            GuardVocabulary::SUMMARY_STATE,
            GuardVocabulary::RECOMMEND_STATE,
        );
    }

    public static function stateObserved(string $probeId): self
    {
        return new self(
            GuardVocabulary::STATE_RETAINED,
            $probeId,
            FindingSeverity::Warning,
            FindingConfidence::Observed,
            GuardVocabulary::SUMMARY_STATE_OBSERVED,
            GuardVocabulary::RECOMMEND_STATE,
        );
    }

    public static function scopeRetained(): self
    {
        return new self(
            GuardVocabulary::STATE_RETAINED,
            'scoped_container',
            FindingSeverity::Critical,
            FindingConfidence::Verified,
            GuardVocabulary::SUMMARY_SCOPE,
            GuardVocabulary::RECOMMEND_SCOPE,
        );
    }

    public static function probeError(string $probeId): self
    {
        return new self(
            GuardVocabulary::PROBE_ERROR,
            self::safeProbeId($probeId),
            FindingSeverity::Warning,
            FindingConfidence::Observed,
            GuardVocabulary::SUMMARY_PROBE,
            GuardVocabulary::RECOMMEND_PROBE,
        );
    }

    public static function incomplete(): self
    {
        return new self(
            GuardVocabulary::PROBE_ERROR,
            'worker_guard',
            FindingSeverity::Warning,
            FindingConfidence::Observed,
            GuardVocabulary::SUMMARY_INCOMPLETE,
            GuardVocabulary::RECOMMEND_PROBE,
        );
    }

    public static function reentrancy(): self
    {
        return new self(
            GuardVocabulary::PROBE_ERROR,
            'worker_guard',
            FindingSeverity::Warning,
            FindingConfidence::Observed,
            GuardVocabulary::SUMMARY_REENTRANCY,
            GuardVocabulary::RECOMMEND_REENTRANCY,
        );
    }

    public static function memoryTrend(): self
    {
        return new self(
            GuardVocabulary::MEMORY_TREND,
            'memory_trend',
            FindingSeverity::Warning,
            FindingConfidence::Suspected,
            GuardVocabulary::SUMMARY_MEMORY,
            GuardVocabulary::RECOMMEND_MEMORY,
        );
    }

    /**
     * @return array{
     *     code: string,
     *     probe_id: string,
     *     severity: string,
     *     confidence: string,
     *     scope: string,
     *     safe_summary: string,
     *     recommendation: string
     * }
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'probe_id' => $this->probeId,
            'severity' => $this->severity->value,
            'confidence' => $this->confidence->value,
            'scope' => $this->scope,
            'safe_summary' => $this->safeSummary,
            'recommendation' => $this->recommendation,
        ];
    }

    public static function safeProbeId(string $probeId): string
    {
        if (preg_match('/^[a-z0-9_]{1,64}$/', $probeId) === 1) {
            return $probeId;
        }

        return 'worker_guard';
    }
}
