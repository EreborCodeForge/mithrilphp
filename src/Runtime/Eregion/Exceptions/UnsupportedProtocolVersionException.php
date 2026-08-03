<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Eregion\Exceptions;

final class UnsupportedProtocolVersionException extends ProtocolException
{
    public function __construct(
        public readonly int $received,
        public readonly int $supported = 1,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "Unsupported protocol version {$received}; supported {$supported}",
            0,
            $previous,
        );
    }
}
