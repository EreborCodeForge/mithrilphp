<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Eregion\Exceptions;

final class ConnectionClosedException extends ProtocolException
{
    public function __construct(string $message = 'Connection closed', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
