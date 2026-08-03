<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Eregion\Messages;

use Erebor\Mithril\Runtime\Eregion\Protocol;

final readonly class ResponseEnvelope
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public string $id,
        public int $status,
        public array $headers,
        public string $body,
        public ResponseMetadata $meta,
        public ?ProtocolError $error = null,
        public int $version = Protocol::VERSION,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => Protocol::TYPE_RESPONSE,
            'version' => $this->version,
            'id' => $this->id,
            'status' => $this->status,
            'headers' => $this->headers,
            'body' => $this->body,
            'error' => $this->error?->toArray(),
            'meta' => $this->meta->toArray(),
        ];
    }
}
