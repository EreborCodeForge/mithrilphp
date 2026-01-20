<?php

declare(strict_types=1);

namespace Erebor\Mithril;

use Erebor\Mithril\Enums\HttpMethod;

final class RouteMatch
{
    /**
     * @param array<int, mixed> $middlewares
     * @param array<string, string> $params
     * @param array<int, string> $allowedMethods
     */
    public function __construct(
        public readonly ?HttpMethod $method,
        public readonly string $path,
        public readonly callable|array|null $handler,
        public readonly array $params,
        public readonly array $middlewares,
        public readonly array $allowedMethods = []
    ) {}
}

