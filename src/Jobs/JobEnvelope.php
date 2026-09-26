<?php

declare(strict_types=1);

namespace Erebor\Mithril\Jobs;

final readonly class JobEnvelope
{
    /**
     * @param array<string, scalar|null> $headers
     */
    public function __construct(
        public string $id,
        public string $name,
        public mixed $payload,
        public int $attempt = 1,
        public array $headers = [],
    ) {}
}
