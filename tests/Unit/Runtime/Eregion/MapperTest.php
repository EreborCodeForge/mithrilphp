<?php

declare(strict_types=1);

namespace Erebor\Mithril\Tests\Unit\Runtime\Eregion;

use Erebor\Mithril\Http\Response;
use Erebor\Mithril\Runtime\Eregion\Messages\RequestEnvelope;
use Erebor\Mithril\Runtime\Eregion\RequestMapper;
use Erebor\Mithril\Runtime\Eregion\ResponseMapper;
use Erebor\Mithril\Runtime\WorkerMetadata;
use PHPUnit\Framework\TestCase;

final class MapperTest extends TestCase
{
    public function testRequestMapperPreservesBinaryBodyAndHeaders(): void
    {
        $envelope = new RequestEnvelope(
            id: 'req-1',
            method: 'POST',
            uri: '/upload?x=1',
            path: '/upload',
            query: 'x=1',
            protocol: 'HTTP/1.1',
            headers: [
                'Content-Type' => ['application/octet-stream'],
                'X-Multi' => ['a', 'b'],
            ],
            body: "\x00\x01\x02binary",
            remoteAddress: '127.0.0.1',
            host: 'localhost',
            scheme: 'https',
            timeoutMs: 5000,
        );

        $request = (new RequestMapper())->map($envelope);

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/upload?x=1', $request->getUri());
        $this->assertSame('/upload', $request->getPath());
        $this->assertSame("\x00\x01\x02binary", $request->getRawBody());
        $this->assertSame(['a', 'b'], $request->headerValues('X-Multi'));
        $this->assertSame('127.0.0.1', $request->server['REMOTE_ADDR']);
        $this->assertSame('https', $request->server['REQUEST_SCHEME']);
        $this->assertSame(['x' => '1'], $request->query);
    }

    public function testResponseMapperKeepsIdAndMultiHeaders(): void
    {
        $response = new Response('hello', 200);
        $response->addHeader('Set-Cookie', 'a=1');
        $response->addHeader('Set-Cookie', 'b=2');

        $envelope = (new ResponseMapper())->map(
            'req-9',
            $response,
            new WorkerMetadata(1, 1024, 2048, true, 'max_requests'),
        );

        $data = $envelope->toArray();
        $this->assertSame('req-9', $data['id']);
        $this->assertSame(200, $data['status']);
        $this->assertSame('hello', $data['body']);
        $this->assertSame(['a=1', 'b=2'], $data['headers']['Set-Cookie']);
        $this->assertTrue($data['meta']['recycle']);
        $this->assertSame('max_requests', $data['meta']['recycle_reason']);
    }
}
