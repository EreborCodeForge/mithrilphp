<?php

declare(strict_types=1);

namespace Erebor\Mithril\Tests\Unit\Runtime;

use Erebor\Mithril\Container;
use Erebor\Mithril\Contracts\JobApplication;
use Erebor\Mithril\Jobs\InMemoryJobTransport;
use Erebor\Mithril\Jobs\JobEnvelope;
use Erebor\Mithril\Jobs\JobResult;
use Erebor\Mithril\Jobs\JobTransport;
use Erebor\Mithril\Runtime\JobWorker;
use Erebor\Mithril\Runtime\WorkerStopReason;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JobWorkerTest extends TestCase
{
    public function testBootFailureReturnsBootstrapFailure(): void
    {
        $app = new class implements JobApplication {
            public function boot(): void
            {
                throw new RuntimeException('boom');
            }

            public function handle(JobEnvelope $job): JobResult
            {
                return JobResult::ack();
            }

            public function getContainer(): Container
            {
                return new Container();
            }
        };

        $result = (new JobWorker($app, new InMemoryJobTransport([])))->runResult();

        $this->assertSame(0, $result->requestsHandled);
        $this->assertSame(WorkerStopReason::BootstrapFailure, $result->stopReason);
    }

    public function testHandleAckCallsTransportAck(): void
    {
        $job = new JobEnvelope('a', 'demo', null);
        $transport = new InMemoryJobTransport([$job]);
        $app = new FakeJobApp(fn () => JobResult::ack());

        $result = (new JobWorker($app, $transport))->runResult();

        $this->assertSame(1, $result->requestsHandled);
        $this->assertSame(['a'], $transport->ackedIds());
        $this->assertSame(WorkerStopReason::Stopped, $result->stopReason);
    }

    public function testHandleRetryAndRejectAreMapped(): void
    {
        $retryJob = new JobEnvelope('r', 'retry-me', null);
        $rejectJob = new JobEnvelope('x', 'reject-me', null);
        $transport = new RecordingJobTransport([$retryJob, $rejectJob]);

        $app = new FakeJobApp(function (JobEnvelope $job): JobResult {
            return match ($job->id) {
                'r' => JobResult::retry('later'),
                default => JobResult::reject('poison'),
            };
        });

        $result = (new JobWorker($app, $transport))->runResult();

        $this->assertSame(2, $result->requestsHandled);
        $this->assertSame(['r'], $transport->retriedIds);
        $this->assertSame(['x'], $transport->rejectedIds);
    }

    public function testExceptionInHandleDoesNotAbortLoop(): void
    {
        $jobs = [
            new JobEnvelope('1', 'fail', null),
            new JobEnvelope('2', 'ok', null),
            new JobEnvelope('3', 'ok', null),
        ];
        $transport = new RecordingJobTransport($jobs);
        $app = new FakeJobApp(function (JobEnvelope $job): JobResult {
            if ($job->id === '1') {
                throw new RuntimeException('handler blew up');
            }

            return JobResult::ack();
        });

        $result = (new JobWorker($app, $transport))->runResult();

        $this->assertSame(3, $result->requestsHandled);
        $this->assertSame(['1'], $transport->retriedIds);
        $this->assertSame(['2', '3'], $transport->ackedIds);
    }

    public function testScopedIsolationAcrossJobs(): void
    {
        $transport = new InMemoryJobTransport([
            new JobEnvelope('1', 'a', null),
            new JobEnvelope('2', 'b', null),
        ]);
        $app = new FakeJobAppWithScope();

        (new JobWorker($app, $transport))->run();

        $this->assertCount(2, $app->scopedIds);
        $this->assertNotSame($app->scopedIds[0], $app->scopedIds[1]);
        $this->assertSame($app->singletonIds[0], $app->singletonIds[1]);
    }

    public function testEmptyTransportServesZero(): void
    {
        $app = new FakeJobApp(fn () => JobResult::ack());
        $result = (new JobWorker($app, new InMemoryJobTransport([])))->runResult();

        $this->assertSame(1, $app->bootCount);
        $this->assertSame(0, $result->requestsHandled);
    }

    public function testScopeCleanupFailureStopsLoop(): void
    {
        $transport = new InMemoryJobTransport([new JobEnvelope('1', 'a', null)]);
        $app = new FakeJobAppWithBrokenScopeCleanup();

        $result = (new JobWorker($app, $transport))->runResult();

        $this->assertSame(1, $result->requestsHandled);
        $this->assertSame(WorkerStopReason::ScopeCleanupFailure, $result->stopReason);
    }
}

/**
 * @param callable(JobEnvelope): JobResult $handler
 */
final class FakeJobApp implements JobApplication
{
    public int $bootCount = 0;

    private Container $container;
    private bool $booted = false;

    /** @var callable(JobEnvelope): JobResult */
    private $handler;

    public function __construct(callable $handler)
    {
        $this->handler = $handler;
        $this->container = new Container();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        $this->bootCount++;
    }

    public function handle(JobEnvelope $job): JobResult
    {
        return ($this->handler)($job);
    }

    public function getContainer(): Container
    {
        return $this->container;
    }
}

final class FakeJobAppWithScope implements JobApplication
{
    /** @var list<string> */
    public array $scopedIds = [];

    /** @var list<string> */
    public array $singletonIds = [];

    private Container $container;
    private bool $booted = false;

    public function __construct()
    {
        $this->container = new Container();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        $shared = new \stdClass();
        $shared->id = 'shared-' . uniqid('', true);
        $this->container->singleton('shared', $shared);
        $this->container->scoped('ticket', static function (): object {
            $o = new \stdClass();
            $o->id = 'scoped-' . uniqid('', true);

            return $o;
        });
    }

    public function handle(JobEnvelope $job): JobResult
    {
        $this->singletonIds[] = $this->container->get('shared')->id;
        $this->scopedIds[] = $this->container->get('ticket')->id;

        return JobResult::ack();
    }

    public function getContainer(): Container
    {
        return $this->container;
    }
}

final class FakeJobAppWithBrokenScopeCleanup implements JobApplication
{
    private Container $container;
    private bool $booted = false;

    public function __construct()
    {
        $this->container = new Container();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        $this->container->scoped('broken', static fn (): object => new class {
            public function cleanup(): void
            {
                throw new RuntimeException('cleanup failed');
            }
        });
    }

    public function handle(JobEnvelope $job): JobResult
    {
        $this->container->get('broken');

        return JobResult::ack();
    }

    public function getContainer(): Container
    {
        return $this->container;
    }
}

/**
 * Records outcomes without requeueing — avoids infinite retry loops in unit tests.
 */
final class RecordingJobTransport implements JobTransport
{
    /** @var list<JobEnvelope> */
    private array $queue;

    /** @var list<string> */
    public array $ackedIds = [];

    /** @var list<string> */
    public array $retriedIds = [];

    /** @var list<string> */
    public array $rejectedIds = [];

    /**
     * @param list<JobEnvelope> $jobs
     */
    public function __construct(array $jobs)
    {
        $this->queue = array_values($jobs);
    }

    public function next(): ?JobEnvelope
    {
        if ($this->queue === []) {
            return null;
        }

        return array_shift($this->queue);
    }

    public function ack(JobEnvelope $job): void
    {
        $this->ackedIds[] = $job->id;
    }

    public function retry(JobEnvelope $job, JobResult $result): void
    {
        $this->retriedIds[] = $job->id;
    }

    public function reject(JobEnvelope $job, JobResult $result): void
    {
        $this->rejectedIds[] = $job->id;
    }
}
