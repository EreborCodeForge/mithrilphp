<?php

declare(strict_types=1);

namespace Erebor\Mithril\Tests\Unit\Jobs;

use Erebor\Mithril\Jobs\JobOutcome;
use Erebor\Mithril\Jobs\JobResult;
use PHPUnit\Framework\TestCase;

final class JobResultTest extends TestCase
{
    public function testAckFactory(): void
    {
        $result = JobResult::ack();

        $this->assertSame(JobOutcome::Ack, $result->outcome);
        $this->assertNull($result->reason);
        $this->assertNull($result->delayMs);
    }

    public function testRetryFactory(): void
    {
        $result = JobResult::retry('busy', 500);

        $this->assertSame(JobOutcome::Retry, $result->outcome);
        $this->assertSame('busy', $result->reason);
        $this->assertSame(500, $result->delayMs);
    }

    public function testRejectFactory(): void
    {
        $result = JobResult::reject('poison');

        $this->assertSame(JobOutcome::Reject, $result->outcome);
        $this->assertSame('poison', $result->reason);
        $this->assertNull($result->delayMs);
    }
}
