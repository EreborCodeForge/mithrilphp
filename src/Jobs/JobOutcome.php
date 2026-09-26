<?php

declare(strict_types=1);

namespace Erebor\Mithril\Jobs;

enum JobOutcome: string
{
    case Ack = 'ack';
    case Retry = 'retry';
    case Reject = 'reject';
}
