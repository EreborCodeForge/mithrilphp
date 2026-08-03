<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Recycling;

final readonly class RecyclingDecision
{
    public function __construct(
        public bool $shouldRecycle,
        public ?string $reason = null,
    ) {}

    public static function keep(): self
    {
        return new self(false, null);
    }

    public static function recycle(string $reason): self
    {
        return new self(true, $reason);
    }
}
