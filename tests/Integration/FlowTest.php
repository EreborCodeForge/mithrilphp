<?php

namespace Erebor\Mithril\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Erebor\Mithril\Router;
use Erebor\Mithril\Support\Pipeline;
use Erebor\Mithril\Http\Request;
use Erebor\Mithril\Http\Response;
use Erebor\Mithril\RouteMatch;
use Erebor\Mithril\Http\HttpContext;
use Erebor\Mithril\Routing\Contracts\HandlerResolver;

class FlowTest extends TestCase
{
    public function testCompleteFlow()
    {
        $router = new Router();
        $router->get('/api/users/{id}', function(Request $req, array $params) {
            return Response::json(['id' => $params['id'], 'name' => 'John Doe']);
        }, [TestMiddleware::class]);

        $request = new Request(
            [], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/users/42'], [], [], []
        );

        $match = $router->match($request);
        $this->assertInstanceOf(RouteMatch::class, $match);

        $pipeline = new Pipeline();
        
        $middlewareInstance = new TestMiddleware();
        
        $response = $pipeline->send($request)
            ->through([$middlewareInstance])
            ->then(function($req) use ($match) {
                return call_user_func($match->handler, $req, $match->params);
            });

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('42', $content['id']);
        $this->assertEquals('John Doe', $content['name']);
        $this->assertTrue(isset($response->getHeaders()['X-Test-Middleware']));
    }

    public function testLoginFlow()
    {
        $router = new Router();
        $router->post('/auth/login', function(Request $req) {
            $data = $req->body;
            if (($data['email'] ?? '') === 'admin@example.com' && ($data['password'] ?? '') === 'secret123') {
                return Response::json(['token' => 'abc-123-xyz', 'expires_in' => 3600]);
            }
            return Response::json(['error' => 'Invalid credentials'], 401);
        });

        $request = new Request(
            [],
            ['email' => 'admin@example.com', 'password' => 'secret123'],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/auth/login'],
            ['Content-Type' => 'application/json'],
            [],
            []
        );

        $match = $router->match($request);
        $this->assertInstanceOf(RouteMatch::class, $match);

        $pipeline = new Pipeline();
        
        $response = $pipeline->send($request)
            ->through([])
            ->then(function($req) use ($match) {
                return call_user_func($match->handler, $req, $match->params);
            });

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        
        $content = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('token', $content);
        $this->assertEquals('abc-123-xyz', $content['token']);
    }

    public function testContextSessionFlow()
    {
        $router = new Router();
        
        $sessionMiddleware = new class {
            public function handle(HttpContext $ctx, $next) {
                $ctx->set('session_user', ['id' => 1, 'name' => 'Maracatu']);
                return $next($ctx);
            }
        };

        $router->get('/profile', function(HttpContext $ctx) {
            $user = $ctx->get('session_user');
            return Response::json(['user' => $user]);
        }, [$sessionMiddleware]);

        $request = new Request(
            [], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/profile'], [], [], []
        );

        $resolver = new class implements HandlerResolver {
            public function resolve(mixed $handler): callable {
                return $handler;
            }
        };

        $pipeline = new Pipeline();

        $response = $router->dispatch($request, $resolver, $pipeline);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Maracatu', $content['user']['name']);
    }
}

class TestMiddleware
{
    public function handle($request, $next)
    {
        $response = $next($request);
        if ($response instanceof Response) {
            $response->setHeader('X-Test-Middleware', 'true');
        }
        return $response;
    }
}
