<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime;

/**
 * Opt-in stage timings. The default build records nothing and does not read request data.
 */
final class StageProfiler
{
    /** @var array<string, int> */
    private array $totals = [];

    public function __construct(
        private readonly bool $enabled = false,
    ) {}

    public static function fromEnvironment(): self
    {
        $flag = getenv('MITHRIL_PROFILE');

        return new self(is_string($flag) && $flag === '1');
    }

    public function measure(string $stage, callable $work): mixed
    {
        if (!$this->enabled) {
            return $work();
        }

        $started = hrtime(true);
        try {
            return $work();
        } finally {
            $this->totals[$stage] = ($this->totals[$stage] ?? 0) + (hrtime(true) - $started);
        }
    }

    /**
     * @return array<string, int> nanoseconds, empty when profiling is off
     */
    public function totals(): array
    {
        return $this->totals;
    }
}
