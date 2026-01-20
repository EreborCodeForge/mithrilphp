<?php

declare(strict_types=1);

namespace Erebor\Mithril;

use Erebor\Mithril\Contracts\PipelineContract;
use Erebor\Mithril\Enums\HttpMethod;
use Erebor\Mithril\Http\HttpContext;
use Erebor\Mithril\Http\Request;
use Erebor\Mithril\Http\Response;
use Erebor\Mithril\Exceptions\HttpException;
use Erebor\Mithril\Routing\Contracts\HandlerResolver;

final class Router
{
    /**
     * Static map: O(1)
     * @var array<string, array<string, array{handler: callable|array, middlewares: array<int, mixed>, path: string}>>
     * method => path => def
     */
    private array $static = [];

    /**
     * Dynamic list: per method
     * @var array<string, array<int, array{pattern: string, handler: callable|array, middlewares: array<int, mixed>, path: string}>>
     */
    private array $dynamic = [];

    /**
     * For 405 detection:
     * pathKey => methods (for static)
     * @var array<string, array<string, true>>
     */
    private array $staticAllowed = [];

    /**
     * For 405 detection on dynamic:
     * A cheap pathKey => methods marker.
     * @var array<string, array<string, true>>
     */
    private array $dynamicAllowed = [];

    /**
     * allowedMethods index by signature+segmentCount (dynamic routes only)
     * @var array<int, array<string, array<string, true>>>
     *
     * Example:
     *  [
     *    2 => [
     *      '/users/{}' => ['GET' => true, 'POST' => true]
     *    ],
     *    3 => [
     *      '/users/{}/posts' => ['GET' => true]
     *    ]
     *  ]
     */
    private array $dynamicAllowedBySegments = [];

    /**
     * Catch-all allowed index:
     * @var array<string, array<string, true>>
     *
     * Example:
     *  [
     *    '/assets/{*}' => ['GET' => true]
     *  ]
     */
    private array $dynamicAllowedCatchAll = [];

    public function get(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->addRoute(HttpMethod::GET, $path, $handler, $middlewares);
    }

    public function post(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->addRoute(HttpMethod::POST, $path, $handler, $middlewares);
    }

    public function put(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->addRoute(HttpMethod::PUT, $path, $handler, $middlewares);
    }

    public function delete(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->addRoute(HttpMethod::DELETE, $path, $handler, $middlewares);
    }

    /**
     * Add a route (static/dynamic).
     */
    private function addRoute(HttpMethod $method, string $path, callable|array $handler, array $middlewares): void
    {
        $methodKey = $method->value;

        // Cheap “path key” for 405 detection:
        // Replace params with {} markers: /users/{id} => /users/{}
        $signature = $this->signatureFromRoute($path);
        $segments  = $this->segmentCount($path);

        $this->dynamicAllowedBySegments[$segments][$signature][$methodKey] = true;

        // se for catch-all, guardar também no índice separado
        if (str_contains($signature, '{*}')) {
            $this->dynamicAllowedCatchAll[$signature][$methodKey] = true;
        }

        // static route? no "{"
        if (strpos($path, '{') === false) {
            $this->static[$methodKey][$path] = [
                'handler' => $handler,
                'middlewares' => $middlewares,
                'path' => $path,
            ];

            $this->staticAllowed[$path][$methodKey] = true;
            return;
        }

        $pattern = $this->compilePattern($path);

        $this->dynamic[$methodKey][] = [
            'pattern' => $pattern,
            'handler' => $handler,
            'middlewares' => $middlewares,
            'path' => $path,
        ];
    }

    /**
     *
     * @throws HttpException
     */
    public function match(Request $request): RouteMatch
    {
        $method = HttpMethod::tryFrom($request->getMethod());
        if (!$method) {
            throw new HttpException(400, 'Invalid HTTP Method');
        }

        $uri = $request->getPath();
        $methodKey = $method->value;

        if (isset($this->static[$methodKey][$uri])) {
            $def = $this->static[$methodKey][$uri];

            return new RouteMatch(
                method: $method,
                path: $def['path'],
                handler: $def['handler'],
                params: [],
                middlewares: $def['middlewares']
            );
        }

        if (!empty($this->dynamic[$methodKey])) {
            foreach ($this->dynamic[$methodKey] as $def) {
                if (preg_match($def['pattern'], $uri, $matches)) {
                    $params = $this->extractNamedParams($matches);

                    return new RouteMatch(
                        method: $method,
                        path: $def['path'],
                        handler: $def['handler'],
                        params: $params,
                        middlewares: $def['middlewares']
                    );
                }
            }
        }

        $allowedMethods = $this->allowedMethodsForPath($uri);

        if (!empty($allowedMethods)) {
            if (in_array($method->value, $allowedMethods, true)) {
                throw new HttpException(404, 'Not Found');
            }

            throw new HttpException(
                statusCode: 405,
                message: 'Method Not Allowed',
                headers: ['Allow' => implode(', ', $allowedMethods)]
            );
        }

        throw new HttpException(404, 'Not Found');
    }

    /**
     *
     * @throws RuntimeException
     */
    public function dispatch(
        Request $request,
        HandlerResolver $resolver,
        PipelineContract $pipeline
    ): Response {
        $match = $this->match($request);
        
        $handler = $resolver->resolve($match->handler);
        $context = new HttpContext($request);

        $destination = static function (HttpContext $ctx) use ($handler, $match): Response {
            $result = $handler($ctx, $match->params);

            return $result instanceof Response 
                ? $result 
                : throw new \RuntimeException('Route handler must return a Response instance.');
        };

        $result = $pipeline
            ->send($context)
            ->through($match->middlewares)
            ->then($destination);

        if (!$result instanceof Response) {
            throw new \RuntimeException('Pipeline must return a Response instance.');
        }

        return $result;
    }

    /**
     * Export all routes in a compile-friendly format.
     * @return array{static: array, dynamic: array}
     */
    public function exportCompiled(): array
    {
        // Already compiled patterns included.
        return [
            'static' => $this->static,
            'dynamic' => $this->dynamic,
        ];
    }

    /**
     * Load compiled routes. Used in prod for routes:compile.
     * @param array{static: array, dynamic: array} $compiled
     */
    public function loadCompiledRoutes(array $compiled): void
    {
        $this->static = $compiled['static'] ?? [];
        $this->dynamic = $compiled['dynamic'] ?? [];

        // rebuild allowed maps cheaply (for 405)
        $this->staticAllowed = [];
        $this->dynamicAllowedBySegments = [];
        $this->dynamicAllowedCatchAll = [];

        foreach ($this->static as $method => $map) {
            foreach ($map as $path => $_def) {
                $this->staticAllowed[$path][$method] = true;
            }
        }

        foreach ($this->dynamic as $method => $list) {
            foreach ($list as $def) {
                $path = $def['path'];
                $signature = $this->signatureFromRoute($path);
                $segments  = $this->segmentCount($path);

                $this->dynamicAllowedBySegments[$segments][$signature][$method] = true;

                if (str_contains($signature, '{*}')) {
                    $this->dynamicAllowedCatchAll[$signature][$method] = true;
                }
            }
        }
    }

    /**
     * Build regex pattern once on addRoute/compile.
     */
    private function compilePattern(string $path): string
    {
        // Replace route params {name:regex?}
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z0-9_]+)(?::([^}]+))?\}/',
            static function (array $m): string {
                $name = $m[1];
                $regex = $m[2] ?? '[^/]+';

                if ($regex === '.*') {
                    return "(?P<$name>.*)";
                }

                return "(?P<$name>$regex)";
            },
            $path
        );

        // Optional trailing slash handling for {path:.*} like patterns at the end
        $pattern = preg_replace('#/(\(\?P<\w+>\.\*\))$#', '(?:/$1)?', (string)$pattern);

        return '#^' . $pattern . '$#';
    }

    /**
     * Extract named params from preg matches.
     * @param array<string|int, mixed> $matches
     * @return array<string, string>
     */
    private function extractNamedParams(array $matches): array
    {
        $params = [];
        foreach ($matches as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $params[$k] = $v;
            }
        }
        return $params;
    }

    private function allowedMethodsForPath(string $uri): array
    {
        $allowed = [];

        if (isset($this->staticAllowed[$uri])) {
            foreach ($this->staticAllowed[$uri] as $m => $_) {
                $allowed[$m] = true;
            }
        }

        $segCount = $this->segmentCount($uri);

        if (isset($this->dynamicAllowedBySegments[$segCount])) {
            foreach ($this->dynamicAllowedBySegments[$segCount] as $signature => $methods) {
                if ($this->matchSignature($signature, $uri)) {
                    foreach ($methods as $m => $_) {
                        $allowed[$m] = true;
                    }
                }
            }
        }

        foreach ($this->possibleCatchAllSignatures($uri) as $catchSig) {
            if (isset($this->dynamicAllowedCatchAll[$catchSig])) {
                foreach ($this->dynamicAllowedCatchAll[$catchSig] as $m => $_) {
                    $allowed[$m] = true;
                }
            }
        }

        if (empty($allowed)) {
            return [];
        }

        $methods = array_keys($allowed);
        sort($methods);

        return $methods;
    }

    private function matchSignature(string $signature, string $uri): bool
    {
        if ($signature === $uri) {
            return true;
        }

        $sigParts = explode('/', trim($signature, '/'));
        $uriParts = explode('/', trim($uri, '/'));

        if (count($sigParts) !== count($uriParts)) {
            return false;
        }

        foreach ($sigParts as $i => $s) {
            if ($s === '{}') {
                continue;
            }
            if ($s !== $uriParts[$i]) {
                return false;
            }
        }

        return true;
    }

    private function signatureFromRoute(string $route): string
    {
        $signature = preg_replace_callback(
            '/\{([a-zA-Z0-9_]+)(?::([^}]+))?\}/',
            static function (array $m): string {
                $regex = $m[2] ?? null;

                if ($regex === '.*') {
                    return '{*}';
                }

                return '{}';
            },
            $route
        );

        if ($signature !== '/' && str_ends_with($signature, '/')) {
            $signature = rtrim($signature, '/');
        }

        return $signature ?: '/';
    }

    private function possibleCatchAllSignatures(string $uri): array
    {
        $uri = trim($uri, '/');
        if ($uri === '') {
             return ['/{*}'];
        }

        $parts = explode('/', $uri);
        $signatures = ['/{*}'];
        $current = '';

        foreach ($parts as $p) {
            $current .= '/' . $p;
            $signatures[] = $current . '/{*}';
        }

        return $signatures;
    }

    private function segmentCount(string $path): int
    {
        $path = $path === '' ? '/' : $path;

        if ($path === '/') {
            return 0;
        }

        return substr_count(trim($path, '/'), '/') + 1;
    }
}
