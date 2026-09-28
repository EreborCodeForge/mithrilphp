<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime;

enum JobWorkerState: string
{
    case Starting = 'starting';
    case Idle = 'idle';
    case Busy = 'busy';
    case Draining = 'draining';
    case Stopped = 'stopped';
    case Failed = 'failed';
}
