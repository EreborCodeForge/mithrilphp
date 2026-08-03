<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Eregion\Messages;

final readonly class ProtocolError
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public string $code,
        public string $message,
        public array $details = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'code' => $this->code,
            'message' => $this->message,
        ];

        if ($this->details !== []) {
            $out['details'] = $this->details;
        }

        return $out;
    }
}
