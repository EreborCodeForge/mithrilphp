<?php

declare(strict_types=1);

namespace Erebor\Mithril\Jobs;

/**
 * Transport that can unblock a blocking poll() during graceful drain.
 */
interface InterruptibleJobTransport extends JobTransport
{
    public function stop(): void;
}
