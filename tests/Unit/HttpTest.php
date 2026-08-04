<?php

declare(strict_types=1);

namespace Erebor\Mithril\Tests\Unit;

use Erebor\Mithril\Http\Request;
use Erebor\Mithril\Http\Response;
use PHPUnit\Framework\TestCase;

class HttpTest extends TestCase
{
    public function testRequestCreation(): void
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/test',
            'CONTENT_TYPE' => 'application/json',
        ];

        $request = new Request([], [], $server, [], [], [], '{"a":1}');

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/test', $request->getUri());
        $this->assertSame('{"a":1}', $request->getRawBody());
    }

    public function testRequestMultiValueHeaders(): void
    {
        $request = new Request(
            [],
            [],
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'],
            ['Accept' => ['text/html', 'application/json'], 'X-Foo' => 'bar'],
            [],
            [],
        );

        $this->assertSame(['text/html', 'application/json'], $request->headerValues('accept'));
        $this->assertSame('text/html', $request->header('Accept'));
        $this->assertSame('bar', $request->header('x-foo'));
    }

    public function testRequestCreateParsesJsonBody(): void
    {
        $request = Request::create(
            'POST',
            '/api',
            ['Content-Type' => 'application/json'],
            '{"name":"mithril"}',
        );

        $this->assertSame('mithril', $request->input('name'));
        $this->assertSame('{"name":"mithril"}', $request->rawBody);
    }

    public function testResponseJson(): void
    {
        $response = Response::json(['status' => 'ok'], 201);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertJsonStringEqualsJsonString(
            json_encode(['status' => 'ok']),
            (string) $response->getContent()
        );
        $this->assertSame(['application/json'], $response->getHeader('Content-Type'));
    }

    public function testResponseHtml(): void
    {
        $html = '<h1>Hello</h1>';
        $response = Response::html($html);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($html, $response->getContent());
    }

    public function testResponseMultiValueHeaders(): void
    {
        $response = new Response('ok', 200);
        $response->addHeader('Set-Cookie', 'a=1');
        $response->addHeader('Set-Cookie', 'b=2');
        $response->setHeader('X-One', 'first');
        $response->setHeader('X-One', 'second');

        $this->assertSame(['a=1', 'b=2'], $response->getHeader('Set-Cookie'));
        $this->assertSame(['second'], $response->getHeader('X-One'));
        $this->assertSame('ok', $response->getBodyBytes());
    }
}
