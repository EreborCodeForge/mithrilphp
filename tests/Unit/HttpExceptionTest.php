<?php

namespace Erebor\Mithril\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Erebor\Mithril\Exceptions\HttpException;

class HttpExceptionTest extends TestCase
{
    public function testStoresStatusCodeAndMessageAndHeaders()
    {
        $headers = ['X-Test' => '1', 'Content-Type' => 'application/json'];
        $exception = new HttpException(418, 'I am a teapot', $headers);

        $this->assertInstanceOf(HttpException::class, $exception);
        $this->assertEquals(418, $exception->getStatusCode());
        $this->assertEquals('I am a teapot', $exception->getMessage());
        $this->assertEquals($headers, $exception->getHeaders());
        $this->assertEquals(418, $exception->getCode());
    }
}

