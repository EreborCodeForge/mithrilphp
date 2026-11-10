<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Guard;

final readonly class GuardDecision
{
    /**
     * @param list<GuardFinding> $findings
     */
    public function __construct(
        public array $findings,
        public bool $recycle,
        public ?string $recycleReason = null,
    ) {}

    public static function none(): self
    {
        static $none = new self([], false, null);

        return $none;
    }
}
