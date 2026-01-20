<?php

declare(strict_types=1);

namespace Erebor\Mithril;

use Erebor\Mithril\Enums\HttpMethod;

final class RouteMatch
{
    public function __construct(
        public readonly ?HttpMethod $method,
        public readonly string $path,
        public readonly \Closure|array|string $handler,
        public readonly array $params,
        public readonly array $middlewares,
        public readonly array $allowedMethods = []
    ) {}
}
