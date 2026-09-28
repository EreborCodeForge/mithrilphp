<?php

declare(strict_types=1);

namespace Erebor\Mithril\Tests\Unit\Runtime;

use Erebor\Mithril\Container;
use Erebor\Mithril\Contracts\JobApplication;
use Erebor\Mithril\Jobs\InMemoryJobDispatcher;
use Erebor\Mithril\Jobs\InMemoryJobTransport;
use Erebor\Mithril\Jobs\InterruptibleJobTransport;
use Erebor\Mithril\Jobs\JobEnvelope;
use Erebor\Mithril\Jobs\JobPollResult;
use Erebor\Mithril\Jobs\JobResult;
use Erebor\Mithril\Jobs\JobTransport;
use Erebor\Mithril\Jobs\LegacyJobTransport;
use Erebor\Mithril\Jobs\LegacyJobTransportAdapter;
use Erebor\Mithril\Runtime\EregionWorkloadMetadata;
use Erebor\Mithril\Runtime\JobWorker;
use Erebor\Mithril\Runtime\JobWorkerObserver;
use Erebor\Mithril\Runtime\NullJobWorkerObserver;
use Erebor\Mithril\Runtime\Recycling\MaxJobsPolicy;
use Erebor\Mithril\Runtime\Recycling\MaxUptimePolicy;
use Erebor\Mithril\Runtime\Recycling\MemoryLimitPolicy;
use Erebor\Mithril\Runtime\Recycling\WorkerContext;
use Erebor\Mithril\Runtime\WorkerExitCode;
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
        $this->assertSame(WorkerStopReason::Stopped, $result->stopReason);
    }

    public function testIdleDoesNotExitUntilStop(): void
    {
        $transport = new ControllablePollTransport([
            JobPollResult::idle(),
            JobPollResult::idle(),
            JobPollResult::job(new JobEnvelope('1', 'a', null)),
            JobPollResult::stop(),
        ]);
        $observer = new RecordingJobWorkerObserver();
        $app = new FakeJobApp(fn () => JobResult::ack());

        $result = (new JobWorker($app, $transport, observer: $observer))->runResult();

        $this->assertSame(1, $result->requestsHandled);
        $this->assertSame(WorkerStopReason::Stopped, $result->stopReason);
        $this->assertSame(2, $observer->idleCount);
    }

    public function testExplicitStopViaPoll(): void
    {
        $transport = new ControllablePollTransport([
            JobPollResult::stop(),
        ]);
        $app = new FakeJobApp(fn () => JobResult::ack());

        $result = (new JobWorker($app, $transport))->runResult();

        $this->assertSame(0, $result->requestsHandled);
        $this->assertSame(WorkerStopReason::Stopped, $result->stopReason);
    }

    public function testScopeCleanupFailureStopsLoop(): void
    {
        $transport = new InMemoryJobTransport([new JobEnvelope('1', 'a', null)]);
        $app = new FakeJobAppWithBrokenScopeCleanup();

        $result = (new JobWorker($app, $transport))->runResult();

        $this->assertSame(1, $result->requestsHandled);
        $this->assertSame(WorkerStopReason::ScopeCleanupFailure, $result->stopReason);
    }

    public function testMaxJobsRecycle(): void
    {
        $transport = new InMemoryJobTransport([
            new JobEnvelope('1', 'a', null),
            new JobEnvelope('2', 'b', null),
            new JobEnvelope('3', 'c', null),
        ]);
        $observer = new RecordingJobWorkerObserver();
        $app = new FakeJobApp(fn () => JobResult::ack());

        $result = (new JobWorker($app, $transport, maxJobs: 2, observer: $observer))->runResult();

        $this->assertSame(2, $result->requestsHandled);
        $this->assertSame(WorkerStopReason::Recycled, $result->stopReason);
        $this->assertSame('max_jobs', $result->recycleReason);
        $this->assertSame(['max_jobs'], $observer->recycleReasons);
        $this->assertSame(10, $result->exitCode()->value);
    }

    public function testMemoryRecycle(): void
    {
        $transport = new InMemoryJobTransport([new JobEnvelope('1', 'a', null)]);
        $app = new FakeJobApp(fn () => JobResult::ack());

        $result = (new JobWorker(
            $app,
            $transport,
            recyclingPolicy: new MemoryLimitPolicy(1),
        ))->runResult();

        $this->assertSame(1, $result->requestsHandled);
        $this->assertSame(WorkerStopReason::Recycled, $result->stopReason);
        $this->assertSame('memory_limit', $result->recycleReason);
    }

    public function testTransportFailureOnPoll(): void
    {
        $transport = new FailingPollTransport();
        $app = new FakeJobApp(fn () => JobResult::ack());

        $result = (new JobWorker($app, $transport))->runResult();

        $this->assertSame(0, $result->requestsHandled);
        $this->assertSame(WorkerStopReason::TransportFailure, $result->stopReason);
        $this->assertSame(21, $result->exitCode()->value);
    }

    public function testObserverHooks(): void
    {
        $transport = new RecordingJobTransport([
            new JobEnvelope('1', 'ok', null),
            new JobEnvelope('2', 'retry', null),
            new JobEnvelope('3', 'reject', null),
        ]);
        $observer = new RecordingJobWorkerObserver();
        $app = new FakeJobApp(function (JobEnvelope $job): JobResult {
            return match ($job->id) {
                '2' => JobResult::retry('later'),
                '3' => JobResult::reject('bad'),
                default => JobResult::ack(),
            };
        });

        (new JobWorker($app, $transport, observer: $observer))->runResult();

        $this->assertSame(1, $observer->bootCount);
        $this->assertSame(['1', '2', '3'], $observer->startedIds);
        $this->assertSame(['1', '2', '3'], $observer->finishedIds);
        $this->assertSame(['2'], $observer->retryIds);
        $this->assertSame(['3'], $observer->rejectIds);
    }

    public function testDispatcherPublishesConsumableJob(): void
    {
        $transport = new InMemoryJobTransport([]);
        $dispatcher = new InMemoryJobDispatcher($transport);
        $dispatcher->dispatch('child', ['n' => 1], ['k' => 'v']);

        $poll = $transport->poll();
        $this->assertTrue($poll->isJob());
        $this->assertSame('child', $poll->job?->name);
        $this->assertSame(['n' => 1], $poll->job?->payload);
        $this->assertSame(['k' => 'v'], $poll->job?->headers);
        $this->assertNotSame('', $poll->job?->id);
    }

    public function testLegacyAdapterMapsNullToStop(): void
    {
        $legacy = new class implements LegacyJobTransport {
            public function next(): ?JobEnvelope
            {
                return null;
            }

            public function ack(JobEnvelope $job): void {}

            public function retry(JobEnvelope $job, JobResult $result): void {}

            public function reject(JobEnvelope $job, JobResult $result): void {}
        };

        $adapter = new LegacyJobTransportAdapter($legacy);
        $this->assertTrue($adapter->poll()->isStop());
    }

    public function testInterruptibleStopEndsIdleLoop(): void
    {
        $transport = new InterruptAfterIdleTransport(idleRounds: 2);
        $app = new FakeJobApp(fn () => JobResult::ack());

        $result = (new JobWorker($app, $transport))->runResult();

        $this->assertSame(0, $result->requestsHandled);
        $this->assertSame(WorkerStopReason::Stopped, $result->stopReason);
        $this->assertTrue($transport->stopped);
    }

    public function testDrainViaPublicSignalPath(): void
    {
        $transport = new DrainOnIdleTransport();
        $app = new FakeJobApp(fn () => JobResult::ack());
        $worker = new JobWorker($app, $transport);
        $transport->worker = $worker;

        $result = $worker->runResult();

        $this->assertSame(0, $result->requestsHandled);
        $this->assertSame(WorkerStopReason::Drained, $result->stopReason);
        $this->assertSame(0, WorkerExitCode::fromStopReason(WorkerStopReason::Drained)->value);
    }

    public function testDrainFinishesCurrentJob(): void
    {
        $handled = [];
        $transport = new DrainDuringJobTransport([
            new JobEnvelope('1', 'a', null),
            new JobEnvelope('2', 'b', null),
        ]);
        $app = new FakeJobApp(function (JobEnvelope $job) use (&$handled, $transport): JobResult {
            $handled[] = $job->id;
            if ($job->id === '1') {
                $transport->triggerDrain();
            }

            return JobResult::ack();
        });
        $worker = new JobWorker($app, $transport);
        $transport->attachWorker($worker);

        $result = $worker->runResult();

        $this->assertSame(['1'], $handled);
        $this->assertSame(1, $result->requestsHandled);
        $this->assertSame(WorkerStopReason::Drained, $result->stopReason);
        $this->assertSame(['1'], $transport->ackedIds);
    }

    public function testEregionMetadataExposed(): void
    {
        $meta = new EregionWorkloadMetadata('jobs', 'w1', '3');
        $worker = new JobWorker(
            new FakeJobApp(fn () => JobResult::ack()),
            new InMemoryJobTransport([]),
            eregionMetadata: $meta,
        );

        $this->assertSame($meta, $worker->eregionMetadata());
        $this->assertTrue($meta->isPresent());
    }

    public function testMaxJobsPolicyUnit(): void
    {
        $decision = (new MaxJobsPolicy(2))->evaluate(new WorkerContext(2, 0, 0));
        $this->assertTrue($decision->shouldRecycle);
        $this->assertSame('max_jobs', $decision->reason);
    }

    public function testMaxUptimePolicyUnit(): void
    {
        $policy = new MaxUptimePolicy(1, startedAt: microtime(true) - 2.0);
        $decision = $policy->evaluate(new WorkerContext(1, 0, 0));
        $this->assertTrue($decision->shouldRecycle);
        $this->assertSame('max_uptime', $decision->reason);
    }

    public function testNullObserverIsSafe(): void
    {
        $observer = new NullJobWorkerObserver();
        $job = new JobEnvelope('1', 'a', null);
        $observer->onBoot();
        $observer->onIdle();
        $observer->onJobStarted($job);
        $observer->onJobFinished($job, JobResult::ack(), 0.01);
        $observer->onRetry($job);
        $observer->onReject($job);
        $observer->onRecycle('max_jobs');
        $this->assertTrue(true);
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

    public function poll(): JobPollResult
    {
        if ($this->queue === []) {
            return JobPollResult::stop();
        }

        return JobPollResult::job(array_shift($this->queue));
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

/**
 * @param list<JobPollResult> $results
 */
final class ControllablePollTransport implements JobTransport
{
    /** @var list<JobPollResult> */
    private array $results;

    /**
     * @param list<JobPollResult> $results
     */
    public function __construct(array $results)
    {
        $this->results = array_values($results);
    }

    public function poll(): JobPollResult
    {
        if ($this->results === []) {
            return JobPollResult::stop();
        }

        return array_shift($this->results);
    }

    public function ack(JobEnvelope $job): void {}

    public function retry(JobEnvelope $job, JobResult $result): void {}

    public function reject(JobEnvelope $job, JobResult $result): void {}
}

final class FailingPollTransport implements JobTransport
{
    public function poll(): JobPollResult
    {
        throw new RuntimeException('broker down');
    }

    public function ack(JobEnvelope $job): void {}

    public function retry(JobEnvelope $job, JobResult $result): void {}

    public function reject(JobEnvelope $job, JobResult $result): void {}
}

final class InterruptAfterIdleTransport implements InterruptibleJobTransport
{
    public bool $stopped = false;
    private int $idleSeen = 0;

    public function __construct(
        private readonly int $idleRounds,
    ) {}

    public function poll(): JobPollResult
    {
        if ($this->stopped) {
            return JobPollResult::stop();
        }

        $this->idleSeen++;
        if ($this->idleSeen >= $this->idleRounds) {
            $this->stop();

            return JobPollResult::stop();
        }

        return JobPollResult::idle();
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function ack(JobEnvelope $job): void {}

    public function retry(JobEnvelope $job, JobResult $result): void {}

    public function reject(JobEnvelope $job, JobResult $result): void {}
}

/**
 * Triggers drain on first idle via reflection (simulates SIGTERM while idle).
 */
final class DrainOnIdleTransport implements InterruptibleJobTransport
{
    public ?JobWorker $worker = null;
    private bool $stopped = false;
    private bool $drained = false;

    public function poll(): JobPollResult
    {
        if ($this->stopped) {
            return JobPollResult::stop();
        }

        if (!$this->drained && $this->worker !== null) {
            $this->drained = true;
            $this->worker->drain();

            return JobPollResult::idle();
        }

        return JobPollResult::idle();
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function ack(JobEnvelope $job): void {}

    public function retry(JobEnvelope $job, JobResult $result): void {}

    public function reject(JobEnvelope $job, JobResult $result): void {}
}

final class DrainDuringJobTransport implements InterruptibleJobTransport
{
    /** @var list<JobEnvelope> */
    private array $queue;

    /** @var list<string> */
    public array $ackedIds = [];

    private bool $stopped = false;
    private ?JobWorker $worker = null;

    /**
     * @param list<JobEnvelope> $jobs
     */
    public function __construct(array $jobs)
    {
        $this->queue = array_values($jobs);
    }

    public function attachWorker(JobWorker $worker): void
    {
        $this->worker = $worker;
    }

    public function triggerDrain(): void
    {
        $this->worker?->drain();
    }

    public function poll(): JobPollResult
    {
        if ($this->stopped) {
            return JobPollResult::stop();
        }
        if ($this->queue === []) {
            return JobPollResult::stop();
        }

        return JobPollResult::job(array_shift($this->queue));
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function ack(JobEnvelope $job): void
    {
        $this->ackedIds[] = $job->id;
    }

    public function retry(JobEnvelope $job, JobResult $result): void {}

    public function reject(JobEnvelope $job, JobResult $result): void {}
}

final class RecordingJobWorkerObserver implements JobWorkerObserver
{
    public int $bootCount = 0;
    public int $idleCount = 0;

    /** @var list<string> */
    public array $startedIds = [];

    /** @var list<string> */
    public array $finishedIds = [];

    /** @var list<string> */
    public array $retryIds = [];

    /** @var list<string> */
    public array $rejectIds = [];

    /** @var list<string> */
    public array $recycleReasons = [];

    public function onBoot(): void
    {
        $this->bootCount++;
    }

    public function onIdle(): void
    {
        $this->idleCount++;
    }

    public function onJobStarted(JobEnvelope $job): void
    {
        $this->startedIds[] = $job->id;
    }

    public function onJobFinished(JobEnvelope $job, JobResult $result, float $duration): void
    {
        $this->finishedIds[] = $job->id;
    }

    public function onRetry(JobEnvelope $job): void
    {
        $this->retryIds[] = $job->id;
    }

    public function onReject(JobEnvelope $job): void
    {
        $this->rejectIds[] = $job->id;
    }

    public function onRecycle(string $reason): void
    {
        $this->recycleReasons[] = $reason;
    }
}
