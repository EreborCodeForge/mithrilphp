<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Eregion;

use Erebor\Mithril\Http\Request;
use Erebor\Mithril\Http\Response;
use Erebor\Mithril\Runtime\Eregion\Exceptions\ConnectionClosedException;
use Erebor\Mithril\Runtime\Eregion\Exceptions\HandshakeException;
use Erebor\Mithril\Runtime\Eregion\Exceptions\ProtocolException;
use Erebor\Mithril\Runtime\Eregion\Exceptions\UnexpectedMessageException;
use Erebor\Mithril\Runtime\Eregion\Messages\HelloMessage;
use Erebor\Mithril\Runtime\Eregion\Messages\ReadyMessage;
use Erebor\Mithril\Runtime\Eregion\Messages\RequestEnvelope;
use Erebor\Mithril\Runtime\Eregion\Messages\ShutdownMessage;
use Erebor\Mithril\Runtime\WorkerMetadata;
use Erebor\Mithril\Runtime\WorkerMetadataAwareBridge;
use Erebor\Mithril\Support\PackageVersion;

/**
 * UDS + MessagePack transport for the Eregion Application Server.
 * Creates and listens on the socket path; Eregion connects and sends hello.
 */
final class EregionBridge implements WorkerMetadataAwareBridge
{
    private \Socket $server;

    private ?\Socket $connection = null;

    private ?FrameReader $reader = null;

    private ?FrameWriter $writer = null;

    private ?string $pendingRequestId = null;

    private bool $handshakeDone = false;

    private bool $shutdownRequested = false;

    private bool $closed = false;

    public function __construct(
        private readonly string $socketPath,
        private readonly string $workerId,
        private readonly int $generation,
        private readonly RequestMapper $requestMapper = new RequestMapper(),
        private readonly ResponseMapper $responseMapper = new ResponseMapper(),
        private readonly int $maxFrameBytes = Protocol::DEFAULT_MAX_FRAME_BYTES,
        private readonly string $mithrilVersion = '',
    ) {
        if (!extension_loaded('sockets')) {
            throw new ProtocolException('ext-sockets is required for EregionBridge');
        }
    }

    public function listenAndHandshake(): void
    {
        $this->createServerSocket();
        $this->acceptConnection();
        $this->performHandshake();
    }

    public function next(): ?Request
    {
        if (!$this->handshakeDone) {
            $this->listenAndHandshake();
        }

        if ($this->shutdownRequested || $this->closed) {
            return null;
        }

        try {
            $message = $this->reader()->read();
        } catch (ConnectionClosedException) {
            $this->closed = true;
            return null;
        }

        $type = (string) ($message['type'] ?? '');

        if ($type === Protocol::TYPE_SHUTDOWN) {
            ShutdownMessage::fromArray($message);
            $this->shutdownRequested = true;
            return null;
        }

        if ($type !== Protocol::TYPE_REQUEST) {
            throw new UnexpectedMessageException(Protocol::TYPE_REQUEST, $type !== '' ? $type : '(missing)');
        }

        $envelope = RequestEnvelope::fromArray($message);
        $this->pendingRequestId = $envelope->id;

        return $this->requestMapper->map($envelope);
    }

    public function respond(Response $response, ?WorkerMetadata $metadata = null): void
    {
        if ($this->pendingRequestId === null) {
            throw new ProtocolException('respond() called without a pending request');
        }

        $metadata ??= new WorkerMetadata(
            requestsHandled: 0,
            memoryUsage: memory_get_usage(true),
            memoryPeak: memory_get_peak_usage(true),
        );

        $envelope = $this->responseMapper->map($this->pendingRequestId, $response, $metadata);
        $this->writer()->write($envelope->toArray());
        $this->pendingRequestId = null;
    }

    public function close(): void
    {
        if ($this->connection instanceof \Socket) {
            @socket_close($this->connection);
            $this->connection = null;
        }

        if (isset($this->server)) {
            @socket_close($this->server);
        }

        if (is_file($this->socketPath)) {
            @unlink($this->socketPath);
        }

        $this->reader = null;
        $this->writer = null;
        $this->closed = true;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function createServerSocket(): void
    {
        $dir = dirname($this->socketPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new ProtocolException("Unable to create socket directory: {$dir}");
        }

        if (file_exists($this->socketPath)) {
            @unlink($this->socketPath);
        }

        $server = socket_create(AF_UNIX, SOCK_STREAM, 0);
        if ($server === false) {
            throw new ProtocolException('socket_create failed: ' . socket_strerror(socket_last_error()));
        }

        if (!socket_bind($server, $this->socketPath)) {
            $err = socket_strerror(socket_last_error($server));
            socket_close($server);
            throw new ProtocolException("socket_bind failed: {$err}");
        }

        @chmod($this->socketPath, 0600);

        if (!socket_listen($server, 1)) {
            $err = socket_strerror(socket_last_error($server));
            socket_close($server);
            throw new ProtocolException("socket_listen failed: {$err}");
        }

        $this->server = $server;
    }

    private function acceptConnection(): void
    {
        $connection = socket_accept($this->server);
        if ($connection === false) {
            throw new ProtocolException('socket_accept failed: ' . socket_strerror(socket_last_error($this->server)));
        }

        $this->connection = $connection;
        $this->reader = new FrameReader($connection, $this->maxFrameBytes);
        $this->writer = new FrameWriter($connection, $this->maxFrameBytes);
    }

    private function performHandshake(): void
    {
        try {
            $message = $this->reader()->read();
        } catch (ConnectionClosedException $e) {
            throw new HandshakeException('Connection closed during handshake', 0, $e);
        }

        $hello = HelloMessage::fromArray($message);

        if ($hello->workerId !== $this->workerId) {
            throw new HandshakeException(
                "worker_id mismatch: hello={$hello->workerId} cli={$this->workerId}"
            );
        }

        if ($hello->generation !== $this->generation) {
            throw new HandshakeException(
                "generation mismatch: hello={$hello->generation} cli={$this->generation}"
            );
        }

        $ready = new ReadyMessage(
            workerId: $this->workerId,
            generation: $this->generation,
            pid: getmypid() ?: 0,
            phpVersion: PHP_VERSION,
            mithrilVersion: $this->mithrilVersion !== '' ? $this->mithrilVersion : PackageVersion::mithril(),
        );

        $this->writer()->write($ready->toArray());
        $this->handshakeDone = true;
    }

    private function reader(): FrameReader
    {
        if ($this->reader === null) {
            throw new ProtocolException('Bridge is not connected');
        }

        return $this->reader;
    }

    private function writer(): FrameWriter
    {
        if ($this->writer === null) {
            throw new ProtocolException('Bridge is not connected');
        }

        return $this->writer;
    }
}
