<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime;

use Erebor\Mithril\Http\Request;
use Erebor\Mithril\Http\Response;

/**
 * Queue-based bridge for tests and in-process multi-request benchmarks.
 */
final class InMemoryBridge implements WorkerMetadataAwareBridge
{
    /** @var list<Request> */
    private array $queue;

    /** @var list<Response> */
    private array $responses = [];

    /** @var list<WorkerMetadata> */
    private array $metadata = [];

    /**
     * @param list<Request> $requests
     */
    public function __construct(array $requests = [])
    {
        $this->queue = array_values($requests);
    }

    public function push(Request $request): void
    {
        $this->queue[] = $request;
    }

    public function next(): ?Request
    {
        if ($this->queue === []) {
            return null;
        }

        return array_shift($this->queue);
    }

    public function respond(Response $response, ?WorkerMetadata $metadata = null): void
    {
        $this->responses[] = $response;
        if ($metadata !== null) {
            $this->metadata[] = $metadata;
        }
    }

    /**
     * @return list<Response>
     */
    public function responses(): array
    {
        return $this->responses;
    }

    /**
     * @return list<WorkerMetadata>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }
}
