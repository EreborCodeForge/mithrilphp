<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Eregion;

use Erebor\Mithril\Runtime\Eregion\Exceptions\ConnectionClosedException;
use Erebor\Mithril\Runtime\Eregion\Exceptions\FrameTooLargeException;
use Erebor\Mithril\Runtime\Eregion\Exceptions\ProtocolException;

/**
 * Writes length-prefixed MessagePack frames (uint32 BE + payload).
 */
final class FrameWriter
{
    /**
     * @param resource $stream
     */
    public function __construct(
        private mixed $stream,
        private readonly int $maxFrameBytes = Protocol::DEFAULT_MAX_FRAME_BYTES,
    ) {
        if (!is_resource($this->stream) && !($this->stream instanceof \Socket)) {
            throw new \InvalidArgumentException('FrameWriter expects a stream resource or Socket');
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    public function write(array $message): void
    {
        if (!function_exists('msgpack_pack')) {
            throw new ProtocolException('ext-msgpack is required');
        }

        $payload = msgpack_pack($message);
        $length = strlen($payload);

        if ($length === 0) {
            throw new ProtocolException('Refusing to write empty MessagePack payload');
        }

        if ($length > $this->maxFrameBytes) {
            throw new FrameTooLargeException($length, $this->maxFrameBytes);
        }

        $frame = pack('N', $length) . $payload;
        $this->writeExact($frame);
    }

    private function writeExact(string $data): void
    {
        $offset = 0;
        $total = strlen($data);

        while ($offset < $total) {
            $written = $this->writeChunk(substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new ConnectionClosedException('Failed to write frame');
            }
            $offset += $written;
        }
    }

    private function writeChunk(string $data): int|false
    {
        if ($this->stream instanceof \Socket) {
            $written = socket_write($this->stream, $data, strlen($data));
            return $written === false ? false : $written;
        }

        $written = fwrite($this->stream, $data);
        return $written === false ? false : $written;
    }
}
