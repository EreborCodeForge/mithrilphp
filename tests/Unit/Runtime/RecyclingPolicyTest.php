<?php

declare(strict_types=1);

namespace Erebor\Mithril\Tests\Unit\Runtime;

use Erebor\Mithril\Runtime\Recycling\CompositeRecyclingPolicy;
use Erebor\Mithril\Runtime\Recycling\MaxRequestsPolicy;
use Erebor\Mithril\Runtime\Recycling\MemoryLimitPolicy;
use Erebor\Mithril\Runtime\Recycling\WorkerContext;
use Erebor\Mithril\Runtime\WorkerExitCode;
use Erebor\Mithril\Runtime\WorkerStopReason;
use PHPUnit\Framework\TestCase;

final class RecyclingPolicyTest extends TestCase
{
    public function testMaxRequestsDisabledWhenZero(): void
    {
        $decision = (new MaxRequestsPolicy(0))->evaluate(new WorkerContext(999, 0, 0));
        $this->assertFalse($decision->shouldRecycle);
    }

    public function testMaxRequestsTriggers(): void
    {
        $decision = (new MaxRequestsPolicy(2))->evaluate(new WorkerContext(2, 0, 0));
        $this->assertTrue($decision->shouldRecycle);
        $this->assertSame('max_requests', $decision->reason);
    }

    public function testMemoryLimitTriggers(): void
    {
        $decision = (new MemoryLimitPolicy(100))->evaluate(new WorkerContext(1, 100, 200));
        $this->assertTrue($decision->shouldRecycle);
        $this->assertSame('memory_limit', $decision->reason);
    }

    public function testCompositeFirstWin(): void
    {
        $policy = new CompositeRecyclingPolicy(
            new MaxRequestsPolicy(1),
            new MemoryLimitPolicy(1),
        );

        $decision = $policy->evaluate(new WorkerContext(1, 999, 999));
        $this->assertSame('max_requests', $decision->reason);
    }

    public function testExitCodeMapping(): void
    {
        $this->assertSame(0, WorkerExitCode::fromStopReason(WorkerStopReason::Stopped)->value);
        $this->assertSame(0, WorkerExitCode::fromStopReason(WorkerStopReason::RemoteShutdown)->value);
        $this->assertSame(10, WorkerExitCode::fromStopReason(WorkerStopReason::Recycled)->value);
        $this->assertSame(20, WorkerExitCode::fromStopReason(WorkerStopReason::BootstrapFailure)->value);
        $this->assertSame(21, WorkerExitCode::fromStopReason(WorkerStopReason::ProtocolFailure)->value);
        $this->assertSame(22, WorkerExitCode::fromStopReason(WorkerStopReason::ScopeCleanupFailure)->value);
    }
}
