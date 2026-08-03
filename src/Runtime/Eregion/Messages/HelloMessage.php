<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Eregion\Messages;

use Erebor\Mithril\Runtime\Eregion\Exceptions\HandshakeException;
use Erebor\Mithril\Runtime\Eregion\Exceptions\UnsupportedProtocolVersionException;
use Erebor\Mithril\Runtime\Eregion\Protocol;

final readonly class HelloMessage
{
    public function __construct(
        public string $protocol,
        public int $protocolVersion,
        public string $runtimeVersion,
        public string $workerId,
        public int $generation,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (($data['type'] ?? null) !== Protocol::TYPE_HELLO) {
            throw new HandshakeException('Expected hello message');
        }

        $protocol = (string) ($data['protocol'] ?? '');
        if ($protocol !== Protocol::NAME) {
            throw new HandshakeException("Unexpected protocol \"{$protocol}\"");
        }

        $version = (int) ($data['protocol_version'] ?? 0);
        if ($version !== Protocol::VERSION) {
            throw new UnsupportedProtocolVersionException($version, Protocol::VERSION);
        }

        $workerId = (string) ($data['worker_id'] ?? '');
        if ($workerId === '') {
            throw new HandshakeException('hello.worker_id is required');
        }

        return new self(
            protocol: $protocol,
            protocolVersion: $version,
            runtimeVersion: (string) ($data['runtime_version'] ?? ''),
            workerId: $workerId,
            generation: (int) ($data['generation'] ?? 0),
        );
    }
}
