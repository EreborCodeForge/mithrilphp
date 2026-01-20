<?php

namespace Erebor\Mithril\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Erebor\Mithril\RouteMatch;
use Erebor\Mithril\Enums\HttpMethod;

class RouteMatchTest extends TestCase
{
    public function testRouteMatchInitialization()
    {
        $handler = fn() => 'test';
        $params = ['id' => '123'];
        $middlewares = ['AuthMiddleware'];

        $routeMatch = new RouteMatch(
            HttpMethod::GET,
            '/users/{id}',
            $handler,
            $params,
            $middlewares
        );

        $this->assertEquals(HttpMethod::GET, $routeMatch->method);
        $this->assertEquals('/users/{id}', $routeMatch->path);
        $this->assertSame($handler, $routeMatch->handler);
        $this->assertEquals($params, $routeMatch->params);
        $this->assertEquals($middlewares, $routeMatch->middlewares);
    }

    public function testRouteMatchImmutability()
    {
        $routeMatch = new RouteMatch(
            HttpMethod::POST,
            '/api/data',
            'Controller@action',
            [],
            []
        );

        $this->assertEquals(HttpMethod::POST, $routeMatch->method);
    }
}
