<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Eregion\Messages;

use Erebor\Mithril\Runtime\Eregion\Protocol;

final readonly class ReadyMessage
{
    public function __construct(
        public string $workerId,
        public int $generation,
        public int $pid,
        public string $phpVersion,
        public string $mithrilVersion,
        public string $protocol = Protocol::NAME,
        public int $protocolVersion = Protocol::VERSION,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => Protocol::TYPE_READY,
            'protocol' => $this->protocol,
            'protocol_version' => $this->protocolVersion,
            'worker_id' => $this->workerId,
            'generation' => $this->generation,
            'pid' => $this->pid,
            'php_version' => $this->phpVersion,
            'mithril_version' => $this->mithrilVersion,
        ];
    }
}
