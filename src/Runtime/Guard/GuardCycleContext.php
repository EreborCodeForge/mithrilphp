<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Guard;

/**
 * Cycle metadata only. This object must not carry a Request, body, header or user identity.
 */
final readonly class GuardCycleContext
{
    public function __construct(
        public int $workerGeneration,
        public int $requestOrdinal,
        public GuardMode $mode,
        public string $correlationId,
        public int $monotonicNs,
        public bool $collectionIncomplete = false,
        public string $scope = 'http',
    ) {}

    public function withIncompleteCollection(): self
    {
        return new self(
            $this->workerGeneration,
            $this->requestOrdinal,
            $this->mode,
            $this->correlationId,
            $this->monotonicNs,
            true,
            $this->scope,
        );
    }
}
