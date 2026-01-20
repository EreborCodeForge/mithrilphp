<?php

declare(strict_types=1);

namespace Erebor\Mithril\Support;

use Erebor\Mithril\Contracts\TraceIdGeneratorContract;

final class FastTraceIdGenerator implements TraceIdGeneratorContract
{
    public function generate(): string
    {
        $time = (int) floor(microtime(true) * 1000);

        $r1 = mt_rand(0, 0xFFFF);
        $r2 = mt_rand(0, 0xFFFF);
        $r3 = mt_rand(0, 0xFFFF);
        $r4 = mt_rand(0, 0xFFFF);
        $r5 = mt_rand(0, 0xFFFF);

        return dechex($time) . '-' . sprintf('%04x%04x%04x%04x%04x', $r1, $r2, $r3, $r4, $r5);
    }
}
