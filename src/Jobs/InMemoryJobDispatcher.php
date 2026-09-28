<?php

declare(strict_types=1);

namespace Erebor\Mithril\Jobs;

/**
 * Publishes jobs onto an InMemoryJobTransport queue.
 */
final class InMemoryJobDispatcher implements JobDispatcher
{
    public function __construct(
        private readonly InMemoryJobTransport $transport,
    ) {}

    public function dispatch(
        string $name,
        array $payload,
        array $headers = [],
    ): void {
        $this->transport->push(new JobEnvelope(
            id: bin2hex(random_bytes(8)),
            name: $name,
            payload: $payload,
            headers: $headers,
        ));
    }
}
