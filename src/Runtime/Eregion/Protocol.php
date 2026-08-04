<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Eregion;

final class Protocol
{
    public const NAME = 'eregion';

    public const VERSION = 1;

    public const DEFAULT_MAX_FRAME_BYTES = 16_777_216;

    public const TYPE_HELLO = 'hello';

    public const TYPE_READY = 'ready';

    public const TYPE_REQUEST = 'request';

    public const TYPE_RESPONSE = 'response';

    public const TYPE_SHUTDOWN = 'shutdown';
}
