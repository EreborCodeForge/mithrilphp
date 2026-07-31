<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime;

use Erebor\Mithril\Http\Request;
use Erebor\Mithril\Http\Response;

/**
 * Transport adapter between the Worker loop and the outside world.
 * Return null from next() to stop the worker.
 */
interface RequestBridge
{
    public function next(): ?Request;

    public function respond(Response $response): void;
}
