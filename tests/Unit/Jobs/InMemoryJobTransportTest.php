<?php

declare(strict_types=1);

namespace Erebor\Mithril\Tests\Unit\Jobs;

use Erebor\Mithril\Jobs\InMemoryJobTransport;
use Erebor\Mithril\Jobs\JobEnvelope;
use Erebor\Mithril\Jobs\JobResult;
use PHPUnit\Framework\TestCase;

final class InMemoryJobTransportTest extends TestCase
{
    public function testFifoPoll(): void
    {
        $a = new JobEnvelope('1', 'a', null);
        $b = new JobEnvelope('2', 'b', null);
        $transport = new InMemoryJobTransport([$a, $b]);

        $this->assertSame($a, $transport->poll()->job);
        $this->assertSame($b, $transport->poll()->job);
        $this->assertTrue($transport->poll()->isStop());
    }

    public function testIdleWhenEmpty(): void
    {
        $transport = new InMemoryJobTransport([], idleWhenEmpty: true);

        $this->assertTrue($transport->poll()->isIdle());
    }

    public function testStopUnblocksPersistentPoll(): void
    {
        $transport = new InMemoryJobTransport([], idleWhenEmpty: true);
        $transport->stop();

        $this->assertTrue($transport->poll()->isStop());
    }

    public function testAckRecordsAndDoesNotRequeue(): void
    {
        $job = new JobEnvelope('1', 'a', null);
        $transport = new InMemoryJobTransport([$job]);

        $taken = $transport->poll()->job;
        $this->assertNotNull($taken);
        $transport->ack($taken);

        $this->assertSame(['1'], $transport->ackedIds());
        $this->assertTrue($transport->poll()->isStop());
    }

    public function testRetryRequeuesWithIncrementedAttempt(): void
    {
        $job = new JobEnvelope('1', 'a', ['x' => 1], attempt: 1);
        $transport = new InMemoryJobTransport([$job]);

        $taken = $transport->poll()->job;
        $this->assertNotNull($taken);
        $transport->retry($taken, JobResult::retry('later', 100));

        $pending = $transport->pending();
        $this->assertCount(1, $pending);
        $this->assertSame('1', $pending[0]->id);
        $this->assertSame(2, $pending[0]->attempt);
        $this->assertSame(['x' => 1], $pending[0]->payload);
    }

    public function testRejectDropsJob(): void
    {
        $job = new JobEnvelope('1', 'a', null);
        $transport = new InMemoryJobTransport([$job]);

        $taken = $transport->poll()->job;
        $this->assertNotNull($taken);
        $transport->reject($taken, JobResult::reject('bad'));

        $this->assertSame(['1'], $transport->rejectedIds());
        $this->assertSame([], $transport->pending());
        $this->assertTrue($transport->poll()->isStop());
    }
}
