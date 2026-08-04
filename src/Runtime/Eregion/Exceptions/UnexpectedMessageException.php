<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Eregion\Exceptions;

final class UnexpectedMessageException extends ProtocolException
{
    public function __construct(
        public readonly string $expected,
        public readonly string $actual,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "Unexpected message type \"{$actual}\"; expected \"{$expected}\"",
            0,
            $previous,
        );
    }
}
