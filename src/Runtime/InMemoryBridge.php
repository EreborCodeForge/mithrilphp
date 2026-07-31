<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime;

use Erebor\Mithril\Http\Request;
use Erebor\Mithril\Http\Response;

/**
 * Queue-based bridge for tests and in-process multi-request benchmarks.
 */
final class InMemoryBridge implements RequestBridge
{
    /** @var list<Request> */
    private array $queue;

    /** @var list<Response> */
    private array $responses = [];

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

    public function respond(Response $response): void
    {
        $this->responses[] = $response;
    }

    /**
     * @return list<Response>
     */
    public function responses(): array
    {
        return $this->responses;
    }
}
