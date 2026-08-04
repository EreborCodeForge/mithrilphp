<?php

declare(strict_types=1);

namespace Erebor\Mithril\Tests\Unit\Runtime\Eregion;

use Erebor\Mithril\Runtime\Eregion\Exceptions\ConnectionClosedException;
use Erebor\Mithril\Runtime\Eregion\Exceptions\FrameTooLargeException;
use Erebor\Mithril\Runtime\Eregion\Exceptions\InvalidFrameException;
use Erebor\Mithril\Runtime\Eregion\FrameReader;
use Erebor\Mithril\Runtime\Eregion\FrameWriter;
use Erebor\Mithril\Runtime\Eregion\Manifest;
use Erebor\Mithril\Runtime\Eregion\Messages\HelloMessage;
use Erebor\Mithril\Runtime\Eregion\Messages\ReadyMessage;
use Erebor\Mithril\Runtime\Eregion\Protocol;
use PHPUnit\Framework\TestCase;

final class ProtocolFoundationTest extends TestCase
{
    public function testHelloMessageParsesAndValidates(): void
    {
        $hello = HelloMessage::fromArray([
            'type' => 'hello',
            'protocol' => 'eregion',
            'protocol_version' => 1,
            'runtime_version' => '0.1.0',
            'worker_id' => 'worker-2',
            'generation' => 8,
        ]);

        $this->assertSame('worker-2', $hello->workerId);
        $this->assertSame(8, $hello->generation);
    }

    public function testReadyMessageSerializes(): void
    {
        $ready = new ReadyMessage(
            workerId: 'worker-1',
            generation: 1,
            pid: 42,
            phpVersion: '8.3.0',
            mithrilVersion: '1.0.0',
        );

        $data = $ready->toArray();
        $this->assertSame(Protocol::TYPE_READY, $data['type']);
        $this->assertSame('worker-1', $data['worker_id']);
        $this->assertSame(42, $data['pid']);
    }

    public function testManifestFromArrayAndRoundTripFile(): void
    {
        $manifest = Manifest::fromArray([
            'application' => 'App\\Kernel',
            'autoload' => '/app/vendor/autoload.php',
            'workingDirectory' => '/app',
            'compiledContainer' => '/app/var/cache/container.php',
            'compiledRoutes' => '/app/var/cache/routes.php',
            'environment' => 'production',
            'worker' => ['maxRequests' => 1000, 'memoryLimitBytes' => 268435456],
            'protocol' => ['version' => 1, 'maxFrameBytes' => 16777216],
        ]);

        $dir = sys_get_temp_dir() . '/mithril-manifest-' . uniqid('', true);
        mkdir($dir);
        $path = $dir . '/eregion.json';
        $manifest->write($path);

        $loaded = Manifest::fromFile($path);
        $this->assertSame('App\\Kernel', $loaded->application);
        $this->assertSame(1000, $loaded->maxRequests());
        $this->assertSame(268435456, $loaded->memoryLimitBytes());

        unlink($path);
        rmdir($dir);
    }

    public function testManifestRejectsMissingFields(): void
    {
        $this->expectException(\Erebor\Mithril\Runtime\Eregion\Exceptions\ProtocolException::class);
        Manifest::fromArray(['application' => 'App\\Kernel']);
    }

    /**
     * @requires extension msgpack
     */
    public function testFrameRoundTrip(): void
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);

        $writer = new FrameWriter($stream, 1024);
        $writer->write(['type' => 'hello', 'protocol' => 'eregion', 'n' => 1]);

        rewind($stream);
        $reader = new FrameReader($stream, 1024);
        $decoded = $reader->read();

        $this->assertSame('hello', $decoded['type']);
        $this->assertSame(1, $decoded['n']);
        fclose($stream);
    }

    /**
     * @requires extension msgpack
     */
    public function testFrameRejectsZeroLength(): void
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, pack('N', 0));
        rewind($stream);

        $this->expectException(InvalidFrameException::class);
        (new FrameReader($stream, 1024))->read();
    }

    /**
     * @requires extension msgpack
     */
    public function testFrameRejectsOversizedBeforeAllocatingPayload(): void
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, pack('N', 2048));
        rewind($stream);

        $this->expectException(FrameTooLargeException::class);
        (new FrameReader($stream, 1024))->read();
    }

    /**
     * @requires extension msgpack
     */
    public function testFramePartialRaises(): void
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, pack('N', 10) . 'abc');
        rewind($stream);

        $this->expectException(InvalidFrameException::class);
        (new FrameReader($stream, 1024))->read();
    }

    /**
     * @requires extension msgpack
     */
    public function testFrameEofRaises(): void
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);
        rewind($stream);

        $this->expectException(ConnectionClosedException::class);
        (new FrameReader($stream, 1024))->read();
    }
}
