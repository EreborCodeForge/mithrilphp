<?php

namespace Erebor\Mithril\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Erebor\Mithril\Http\Request;
use Erebor\Mithril\Http\Response;

class HttpTest extends TestCase
{
    public function testRequestCreation()
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/test',
            'CONTENT_TYPE' => 'application/json'
        ];
        
        $request = new Request([], [], $server, [], [], []);
        
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('/test', $request->getUri());
    }

    public function testResponseJson()
    {
        $response = Response::json(['status' => 'ok'], 201);
        
        $this->assertEquals(201, $response->getStatusCode());
        $this->assertJsonStringEqualsJsonString(
            json_encode(['status' => 'ok']),
            $response->getContent()
        );
    }

    public function testResponseHtml()
    {
        $html = '<h1>Hello</h1>';
        $response = Response::html($html);
        
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($html, $response->getContent());
    }
}
