<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Guard\Probe;

use Erebor\Mithril\Runtime\Guard\GuardCycleContext;
use Erebor\Mithril\Runtime\Guard\GuardFinding;
use Erebor\Mithril\Runtime\Guard\GuardProbe;

/**
 * Sampled memory trend. A rising real-usage window is suspected, never a recycle signal.
 *
 * @phpstan-type Sample array{usage: int, real: int, peak: int}
 */
final class MemoryTrendProbe implements GuardProbe
{
    /** @var list<int> */
    private array $samples = [];

    private int $seen = 0;

    private bool $sampledOnce = false;

    private bool $active = false;

    /**
     * @param null|(callable(): array{usage: int, real: int, peak: int}) $reader
     */
    public function __construct(
        private readonly int $sampleEveryNRequests = 10,
        private readonly int $window = 4,
        private readonly int $warmup = 2,
        private readonly mixed $reader = null,
    ) {}

    public function id(): string
    {
        return 'memory_trend';
    }

    public function before(GuardCycleContext $context): void
    {
        $n = $this->sampleEveryNRequests;
        $this->active = $n > 0 && $context->requestOrdinal % $n === 0;
    }

    public function after(GuardCycleContext $context): array
    {
        if (!$this->active || $context->collectionIncomplete) {
            return [];
        }

        $sample = $this->read();
        $this->sampledOnce = true;
        $this->seen++;
        if ($this->seen <= $this->warmup) {
            return [];
        }

        $this->samples[] = $sample['real'];
        if (count($this->samples) > $this->window) {
            $this->samples = array_slice($this->samples, -$this->window);
        }
        if (count($this->samples) < $this->window) {
            return [];
        }

        for ($i = 1, $n = count($this->samples); $i < $n; $i++) {
            if ($this->samples[$i] <= $this->samples[$i - 1]) {
                return [];
            }
        }

        return [GuardFinding::memoryTrend()];
    }

    public function coverage(): string
    {
        return $this->sampledOnce ? 'covered' : 'skipped';
    }

    public function sampleable(): bool
    {
        return true;
    }

    public function prepare(): void
    {
    }

    /**
     * @return array{usage: int, real: int, peak: int}
     */
    private function read(): array
    {
        if ($this->reader !== null) {
            $sample = ($this->reader)();
            return [
                'usage' => (int) $sample['usage'],
                'real' => (int) $sample['real'],
                'peak' => (int) $sample['peak'],
            ];
        }

        return [
            'usage' => memory_get_usage(false),
            'real' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
        ];
    }
}
