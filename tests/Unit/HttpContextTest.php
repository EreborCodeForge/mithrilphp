<?php

namespace Erebor\Mithril\Tests\Unit;

use Erebor\Mithril\Http\HttpContext;
use PHPUnit\Framework\TestCase;
use Erebor\Mithril\Http\Request;

class HttpContextTest extends TestCase
{
    private Request $request;
    private HttpContext $context;

    protected function setUp(): void
    {
        $this->request = new Request([], [], [], [], [], []);
        $this->context = new HttpContext($this->request);
    }

    public function testInitialization()
    {
        $this->assertSame($this->request, $this->context->request);
        $this->assertNotEmpty($this->context->traceId());
        $this->assertNotNull($this->context->attributes());
        $this->assertNotNull($this->context->items());
    }

    public function testTraceId()
    {
        $id = 'custom-trace-id';
        $this->context->setTraceId($id);
        $this->assertEquals($id, $this->context->traceId());
    }

    public function testAttributesManipulation()
    {
        $this->assertFalse($this->context->has('key'));
        $this->assertNull($this->context->get('key'));
        $this->assertEquals('default', $this->context->get('key', 'default'));

        $this->context->set('key', 'value');
        $this->assertTrue($this->context->has('key'));
        $this->assertEquals('value', $this->context->get('key'));

        $this->context->forget('key');
        $this->assertFalse($this->context->has('key'));
    }

    public function testAbort()
    {
        $this->expectException(\Erebor\Mithril\Exceptions\HttpException::class);
        $this->expectExceptionCode(403);
        $this->expectExceptionMessage('Forbidden');

        $this->context->abort(403, 'Forbidden');
    }

    public function testItemsOnce()
    {
        $counter = 0;
        $factory = function () use (&$counter) {
            $counter++;
            return 'computed-value';
        };

        $val1 = $this->context->once('cached_key', $factory);
        $this->assertEquals('computed-value', $val1);
        $this->assertEquals(1, $counter);

        $val2 = $this->context->once('cached_key', $factory);
        $this->assertEquals('computed-value', $val2);
        $this->assertEquals(1, $counter);
    }

    public function testCloneWithRequest()
    {
        $this->context->set('shared', 'data');
        $this->context->once('cached', fn() => 'item');
        
        $originalTraceId = $this->context->traceId();

        $newRequest = new Request([], [], ['REQUEST_URI' => '/new'], [], [], []);
        $newContext = $this->context->cloneWithRequest($newRequest);

        $this->assertNotSame($this->context, $newContext);
        $this->assertSame($newRequest, $newContext->request);
        $this->assertEquals($originalTraceId, $newContext->traceId());
        
        $this->assertEquals('data', $newContext->get('shared'));
        
        $newContext->set('shared', 'new-data');
        $this->assertEquals('data', $this->context->get('shared'));
        $this->assertEquals('new-data', $newContext->get('shared'));
    }
}
