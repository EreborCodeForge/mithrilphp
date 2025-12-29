<?php

declare(strict_types=1);

namespace Erebor\Mithril;

use Erebor\Mithril\Enums\HttpMethod;
use Erebor\Mithril\Http\Request;
use Erebor\Mithril\Http\Response;

class Router
{
    private array $routes = [];
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

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

    private function addRoute(HttpMethod $method, string $path, callable|array $handler, array $middlewares): void
    {
        // Converte {param} e {param:regex} em named groups
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z0-9_]+)(?::([^}]+))?\}/',
            function ($matches) use ($path) {
                $param = $matches[1];
                $regex = $matches[2] ?? '[^/]+';

                // Se for catch-all (.*) e estiver no final do path
                // Ex: /resources/{path:.*}
                // Queremos casar também /resources e /resources/ (path vazio)
                if ($regex === '.*') {
                    return "(?P<$param>.*)";
                }

                return "(?P<$param>$regex)";
            },
            $path
        );

        // ✅ Se o padrão termina com "/(?P<xxx>.*)" então torna o "/xxx" opcional
        // Isso permite: /resources e /resources/ e /resources/abc/def
        $pattern = preg_replace('#/(\(\?P<\w+>\.\*\))$#', '(?:/$1)?', $pattern);

        $pattern = "#^" . $pattern . "$#";

        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'handler' => $handler,
            'middlewares' => $middlewares
        ];
    }

    public function dispatch(Request $request): Response
    {
        $uri = parse_url($request->getUri(), PHP_URL_PATH);
        $method = HttpMethod::tryFrom($request->getMethod());

        if (!$method) {
             return (new Response())->json(['error' => 'Method Not Allowed'], 405);
        }

        foreach ($this->routes as $route) {
            if ($this->matchRoute($route, $method, $uri, $params)) {
                return $this->handleRoute($route, $request, $params);
            }
        }
        
        return (new Response())->json(['error' => 'Not Found'], 404);
    }

    private function matchRoute(array $route, HttpMethod $method, string $uri, ?array &$params): bool
    {
        if ($route['method'] !== $method) {
            return false;
        }

        if (!preg_match($route['pattern'], $uri, $matches)) {
            return false;
        }

        // Filter out numeric keys from matches
        $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
        return true;
    }

    private function handleRoute(array $route, Request $request, array $params): Response
    {
        // Pipeline for middleware
        $handler = function (Request $req) use ($route, $params) {
            if (is_array($route['handler'])) {
                [$controller, $action] = $route['handler'];
                $controllerInstance = $this->container->get($controller);

                // ✅ Se existir 'path', passa como string (controller signature serve(Request, string))
                if (array_key_exists('path', $params)) {
                    return $controllerInstance->$action($req, $params['path']);
                }

                // fallback: mantém compatibilidade (controller que recebe array)
                return $controllerInstance->$action($req, $params);
            }

            return call_user_func($route['handler'], $req, $params);
        };

        return $this->runMiddleware($request, $route['middlewares'], $handler);
    }

    private function runMiddleware(Request $request, array $middlewares, callable $target): Response
    {
        if (empty($middlewares)) {
            return $target($request);
        }

        $middlewareClass = array_shift($middlewares);
        $middleware = $this->container->get($middlewareClass);

        return $middleware->handle($request, function (Request $req) use ($middlewares, $target) {
            return $this->runMiddleware($req, $middlewares, $target);
        });
    }
}
