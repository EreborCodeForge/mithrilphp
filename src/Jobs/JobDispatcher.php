<?php

declare(strict_types=1);

namespace Erebor\Mithril\Jobs;

/**
 * Fan-out: handlers publish subjobs to a broker. Does not spawn processes.
 */
interface JobDispatcher
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, scalar|null> $headers
     */
    public function dispatch(
        string $name,
        array $payload,
        array $headers = [],
    ): void;
}
