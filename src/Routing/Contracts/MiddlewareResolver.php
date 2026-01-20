<?php

declare(strict_types=1);

namespace Erebor\Mithril\Routing\Contracts;

interface MiddlewareResolver
{
    public function resolve(string|object|callable $middleware): callable;
}
