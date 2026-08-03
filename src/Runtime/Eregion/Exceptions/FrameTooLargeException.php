<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Eregion\Exceptions;

final class FrameTooLargeException extends ProtocolException
{
    public function __construct(
        public readonly int $length,
        public readonly int $maxFrameBytes,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "Frame length {$length} exceeds max {$maxFrameBytes} bytes",
            0,
            $previous,
        );
    }
}
