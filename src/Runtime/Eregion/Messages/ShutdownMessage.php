<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Eregion\Messages;

use Erebor\Mithril\Runtime\Eregion\Exceptions\UnexpectedMessageException;
use Erebor\Mithril\Runtime\Eregion\Protocol;

final readonly class ShutdownMessage
{
    public function __construct(
        public string $reason = 'graceful',
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $type = (string) ($data['type'] ?? '');
        if ($type !== Protocol::TYPE_SHUTDOWN) {
            throw new UnexpectedMessageException(Protocol::TYPE_SHUTDOWN, $type !== '' ? $type : '(missing)');
        }

        return new self((string) ($data['reason'] ?? 'graceful'));
    }
}
