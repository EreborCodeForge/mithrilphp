<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Eregion;

use Erebor\Mithril\Runtime\Eregion\Exceptions\ConnectionClosedException;
use Erebor\Mithril\Runtime\Eregion\Exceptions\FrameTooLargeException;
use Erebor\Mithril\Runtime\Eregion\Exceptions\InvalidFrameException;
use Erebor\Mithril\Runtime\Eregion\Exceptions\ProtocolException;

/**
 * Reads length-prefixed MessagePack frames (uint32 BE + payload).
 */
final class FrameReader
{
    /**
     * @param resource $stream
     */
    public function __construct(
        private mixed $stream,
        private readonly int $maxFrameBytes = Protocol::DEFAULT_MAX_FRAME_BYTES,
    ) {
        if (!is_resource($this->stream) && !($this->stream instanceof \Socket)) {
            throw new \InvalidArgumentException('FrameReader expects a stream resource or Socket');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $header = $this->readExact(4);
        $unpacked = unpack('Nlength', $header);
        if ($unpacked === false) {
            throw new InvalidFrameException('Failed to unpack frame length header');
        }

        $length = (int) $unpacked['length'];
        if ($length === 0) {
            throw new InvalidFrameException('Frame length must not be zero');
        }

        if ($length > $this->maxFrameBytes) {
            throw new FrameTooLargeException($length, $this->maxFrameBytes);
        }

        $payload = $this->readExact($length);

        if (!function_exists('msgpack_unpack')) {
            throw new ProtocolException('ext-msgpack is required');
        }

        $decoded = msgpack_unpack($payload);
        if (!is_array($decoded)) {
            throw new InvalidFrameException('MessagePack payload must decode to an array');
        }

        return $decoded;
    }

    private function readExact(int $length): string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = $this->readChunk($length - strlen($buffer));
            if ($chunk === '' || $chunk === false) {
                if ($buffer === '') {
                    throw new ConnectionClosedException('EOF while reading frame');
                }
                throw new InvalidFrameException('Partial frame received');
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function readChunk(int $max): string|false
    {
        if ($this->stream instanceof \Socket) {
            $chunk = socket_read($this->stream, $max, PHP_BINARY_READ);
            return $chunk === false ? false : $chunk;
        }

        return fread($this->stream, $max);
    }
}
