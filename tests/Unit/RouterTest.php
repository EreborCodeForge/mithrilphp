<?php

namespace Erebor\Mithril\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Erebor\Mithril\Router;
use Erebor\Mithril\Exceptions\HttpException;
use Erebor\Mithril\Http\Request;
use Erebor\Mithril\RouteMatch;
use Erebor\Mithril\Enums\HttpMethod;
use Erebor\Mithril\Http\Response;
use Erebor\Mithril\Routing\Contracts\HandlerResolver;
use Erebor\Mithril\Contracts\PipelineContract;
use Erebor\Mithril\Http\HttpContext;

class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    public function testDispatchSuccess()
    {
        $this->router->get('/api/test', fn() => 'success');

        $request = new Request(
            [], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/test'], [], [], []
        );

        $resolver = $this->createMock(HandlerResolver::class);
        $resolver->method('resolve')
            ->willReturn(fn(HttpContext $ctx) => Response::json(['status' => 'ok']));

        $pipeline = $this->createMock(PipelineContract::class);
        $pipeline->method('send')->willReturnSelf();
        $pipeline->method('through')->willReturnSelf();
        $pipeline->method('then')->willReturnCallback(function ($destination) {
            $dummyCtx = new HttpContext(new Request([],[],[],[],[],[]));
            return $destination($dummyCtx);
        });

        $response = $this->router->dispatch($request, $resolver, $pipeline);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('ok', $content['status']);
    }

    public function testDispatchNotFound()
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(404);
        $this->expectExceptionMessage('Not Found');

        $request = new Request(
            [], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/not-found'], [], [], []
        );

        $resolver = $this->createMock(HandlerResolver::class);
        $pipeline = $this->createMock(PipelineContract::class);

        $this->router->dispatch($request, $resolver, $pipeline);
    }

    public function testDispatchInvalidResponse()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Route handler must return a Response instance');

        $this->router->get('/fail', fn() => 'fail');
        $request = new Request(
            [], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/fail'], [], [], []
        );

        $resolver = $this->createMock(HandlerResolver::class);
        $resolver->method('resolve')
            ->willReturn(fn() => 'not-a-response-object');

        $pipeline = $this->createMock(PipelineContract::class);
        $pipeline->method('send')->willReturnSelf();
        $pipeline->method('through')->willReturnSelf();
        $pipeline->method('then')->willReturnCallback(function ($destination) {
            $dummyCtx = new HttpContext(new Request([],[],[],[],[],[]));
            return $destination($dummyCtx);
        });

        $this->router->dispatch($request, $resolver, $pipeline);
    }

    public function testDispatchMethodNotAllowed()
    {
        $this->router->get('/hello', function () {
            return 'Hello';
        });

        $request = new Request(
            [], [], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/hello'], [], [], []
        );

        $resolver = $this->createMock(HandlerResolver::class);
        $pipeline = $this->createMock(PipelineContract::class);

        try {
            $this->router->dispatch($request, $resolver, $pipeline);
            $this->fail('Expected HttpException for method not allowed');
        } catch (HttpException $e) {
            $this->assertSame(405, $e->getStatusCode());
            $this->assertSame('Method Not Allowed', $e->getMessage());
            $headers = $e->getHeaders();
            $this->assertArrayHasKey('Allow', $headers);
            $this->assertSame('GET', $headers['Allow']);
        }
    }

    public function testMatchFound()
    {
        $this->router->get('/hello/{name}', function () { return 'Hello'; });

        $request = new Request(
            [], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/hello/World'], [], [], []
        );

        $match = $this->router->match($request);

        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertEquals(['name' => 'World'], $match->params);
        $this->assertEquals(HttpMethod::GET, $match->method);
        $this->assertEquals('/hello/{name}', $match->path);
        $this->assertSame([], $match->allowedMethods);
    }

    public function testMatchNotFound()
    {
        $this->router->get('/hello', function () { return 'Hello'; });

        $request = new Request(
            [], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/bye'], [], [], []
        );

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(404);
        $this->expectExceptionMessage('Not Found');

        $this->router->match($request);
    }

    public function testMethodNotAllowed()
    {
         $this->router->get('/hello', function () { return 'Hello'; });

         $request = new Request(
            [], [], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/hello'], [], [], []
        );

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(405);
        $this->expectExceptionMessage('Method Not Allowed');

        $this->router->match($request);
    }

    public function testRegexConstraints()
    {
        $this->router->get('/users/{id:\d+}', fn() => 'User');

        $requestValid = new Request(
            [], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/users/123'], [], [], []
        );
        
        $match = $this->router->match($requestValid);
        $this->assertNotNull($match);
        $this->assertEquals(['id' => '123'], $match->params);
    }

    public function testRegexConstraintsNotFound()
    {
        $this->router->get('/users/{id:\d+}', fn() => 'User');

        $requestInvalid = new Request(
            [], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/users/abc'], [], [], []
        );

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(404);
        $this->expectExceptionMessage('Not Found');

        $this->router->match($requestInvalid);
    }

    public function testMiddlewaresAreReturned()
    {
        $middlewares = ['Auth', 'Log'];
        $this->router->post('/api/secure', fn() => 'Secure', $middlewares);

        $request = new Request(
            [], [], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/secure'], [], [], []
        );

        $match = $this->router->match($request);
        $this->assertNotNull($match);
        $this->assertEquals($middlewares, $match->middlewares);
    }

    public function testAllHttpVerbs()
    {
        $this->router->get('/test', fn() => 'GET');
        $this->router->post('/test', fn() => 'POST');
        $this->router->put('/test', fn() => 'PUT');
        $this->router->delete('/test', fn() => 'DELETE');

        $verbs = ['GET', 'POST', 'PUT', 'DELETE'];

        foreach ($verbs as $verb) {
            $request = new Request(
                [], [], ['REQUEST_METHOD' => $verb, 'REQUEST_URI' => '/test'], [], [], []
            );
            $match = $this->router->match($request);
            $this->assertNotNull($match, "Failed to match $verb");
            $this->assertEquals(HttpMethod::from($verb), $match->method);
        }
    }

    public function testWildcardRoute()
    {
        $this->router->get('/files/{path:.*}', fn() => 'File');

        $request = new Request(
            [], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/files/css/style.css'], [], [], []
        );

        $match = $this->router->match($request);
        $this->assertNotNull($match);
        $this->assertEquals(['path' => 'css/style.css'], $match->params);
    }
}
