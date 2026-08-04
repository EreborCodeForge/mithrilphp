<?php

declare(strict_types=1);

namespace Erebor\Mithril\Tests\Unit\Runtime\Eregion;

use Erebor\Mithril\Runtime\Eregion\Exceptions\ProtocolException;
use Erebor\Mithril\Runtime\Eregion\WorkerCliOptions;
use PHPUnit\Framework\TestCase;

final class WorkerCliOptionsTest extends TestCase
{
    public function testParsesFlags(): void
    {
        $opts = WorkerCliOptions::fromArgv([
            'eregion-worker',
            '--socket=/tmp/w.sock',
            '--worker-id=worker-2',
            '--generation=8',
            '--max-requests=1000',
            '--memory-limit-mb=256',
            '--manifest=/app/var/runtime/eregion.json',
        ]);

        $this->assertSame('/tmp/w.sock', $opts->socket);
        $this->assertSame('worker-2', $opts->workerId);
        $this->assertSame(8, $opts->generation);
        $this->assertSame(1000, $opts->maxRequests);
        $this->assertSame(256, $opts->memoryLimitMb);
        $this->assertSame(268435456, $opts->memoryLimitBytes());
    }

    public function testMissingFlagFails(): void
    {
        $this->expectException(ProtocolException::class);
        WorkerCliOptions::fromArgv([
            'eregion-worker',
            '--socket=/tmp/w.sock',
        ]);
    }

    public function testZeroMemoryDisablesBytes(): void
    {
        $opts = WorkerCliOptions::fromArgv([
            'eregion-worker',
            '--socket=/tmp/w.sock',
            '--worker-id=worker-1',
            '--generation=1',
            '--max-requests=0',
            '--memory-limit-mb=0',
            '--manifest=/app/m.json',
        ]);

        $this->assertSame(0, $opts->memoryLimitBytes());
    }
}
