<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime;

use Erebor\Mithril\Http\Request;
use Erebor\Mithril\Http\Response;

/**
 * Classic FPM / php -S: one request from globals, then stop.
 * Same Worker path as long-running bridges — boot still happens once per process.
 */
final class FpmOnceBridge implements RequestBridge
{
    private bool $done = false;

    public function next(): ?Request
    {
        if ($this->done) {
            return null;
        }

        $this->done = true;

        return Request::createFromGlobals();
    }

    public function respond(Response $response): void
    {
        $response->send();
    }
}
