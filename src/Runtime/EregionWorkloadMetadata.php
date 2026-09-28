<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime;

/**
 * Optional supervisor metadata from Eregion env vars. Standalone works without these.
 */
final readonly class EregionWorkloadMetadata
{
    public function __construct(
        public ?string $workload = null,
        public ?string $workerId = null,
        public ?string $generation = null,
    ) {}

    public static function fromEnvironment(): self
    {
        return new self(
            workload: self::envOrNull('EREGION_WORKLOAD'),
            workerId: self::envOrNull('EREGION_WORKER_ID'),
            generation: self::envOrNull('EREGION_GENERATION'),
        );
    }

    public function isPresent(): bool
    {
        return $this->workload !== null
            || $this->workerId !== null
            || $this->generation !== null;
    }

    private static function envOrNull(string $name): ?string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return null;
        }

        return $value;
    }
}
