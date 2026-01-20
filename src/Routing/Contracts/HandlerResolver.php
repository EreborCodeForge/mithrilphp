<?php

declare(strict_types=1);

namespace Erebor\Mithril\Routing\Contracts;

interface HandlerResolver
{
    public function resolve(callable|array|string $handler): callable;
}
