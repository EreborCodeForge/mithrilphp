<?php

declare(strict_types=1);

namespace Erebor\Mithril\Tests\Unit\Jobs;

use Erebor\Mithril\Jobs\InMemoryJobTransport;
use Erebor\Mithril\Jobs\JobEnvelope;
use Erebor\Mithril\Jobs\JobResult;
use PHPUnit\Framework\TestCase;

final class InMemoryJobTransportTest extends TestCase
{
    public function testFifoNext(): void
    {
        $a = new JobEnvelope('1', 'a', null);
        $b = new JobEnvelope('2', 'b', null);
        $transport = new InMemoryJobTransport([$a, $b]);

        $this->assertSame($a, $transport->next());
        $this->assertSame($b, $transport->next());
        $this->assertNull($transport->next());
    }

    public function testAckRecordsAndDoesNotRequeue(): void
    {
        $job = new JobEnvelope('1', 'a', null);
        $transport = new InMemoryJobTransport([$job]);

        $taken = $transport->next();
        $this->assertNotNull($taken);
        $transport->ack($taken);

        $this->assertSame(['1'], $transport->ackedIds());
        $this->assertNull($transport->next());
    }

    public function testRetryRequeuesWithIncrementedAttempt(): void
    {
        $job = new JobEnvelope('1', 'a', ['x' => 1], attempt: 1);
        $transport = new InMemoryJobTransport([$job]);

        $taken = $transport->next();
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

        $taken = $transport->next();
        $this->assertNotNull($taken);
        $transport->reject($taken, JobResult::reject('bad'));

        $this->assertSame(['1'], $transport->rejectedIds());
        $this->assertSame([], $transport->pending());
        $this->assertNull($transport->next());
    }
}
