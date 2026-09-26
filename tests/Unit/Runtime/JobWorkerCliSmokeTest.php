<?php

declare(strict_types=1);

namespace Erebor\Mithril\Tests\Unit\Runtime;

use Erebor\Mithril\Container;
use Erebor\Mithril\Contracts\JobApplication;
use Erebor\Mithril\Jobs\InMemoryJobTransport;
use Erebor\Mithril\Jobs\JobEnvelope;
use Erebor\Mithril\Jobs\JobResult;
use Erebor\Mithril\Jobs\JobTransport;
use Erebor\Mithril\Runtime\JobWorkerLauncher;
use Erebor\Mithril\Runtime\WorkerExitCode;
use Erebor\Mithril\Runtime\WorkerStopReason;
use PHPUnit\Framework\TestCase;

final class JobWorkerCliSmokeTest extends TestCase
{
    private ?string $prevEnv = null;

    protected function setUp(): void
    {
        parent::setUp();
        $env = getenv('MITHRIL_JOB_KERNEL');
        $this->prevEnv = $env === false ? null : $env;
        putenv('MITHRIL_JOB_KERNEL');
        unset($_ENV['MITHRIL_JOB_KERNEL'], $_SERVER['MITHRIL_JOB_KERNEL']);
    }

    protected function tearDown(): void
    {
        if ($this->prevEnv === null) {
            putenv('MITHRIL_JOB_KERNEL');
            unset($_ENV['MITHRIL_JOB_KERNEL'], $_SERVER['MITHRIL_JOB_KERNEL']);
        } else {
            putenv('MITHRIL_JOB_KERNEL=' . $this->prevEnv);
            $_ENV['MITHRIL_JOB_KERNEL'] = $this->prevEnv;
        }
        parent::tearDown();
    }

    public function testLauncherRunsStubKernelWithInMemoryBinding(): void
    {
        $result = (new JobWorkerLauncher())->runFromArgv(
            ['job-worker', '--kernel=' . SmokeJobKernel::class],
            getcwd() ?: null,
        );

        $this->assertSame(WorkerStopReason::Stopped, $result->stopReason);
        $this->assertSame(2, $result->requestsHandled);
        $this->assertSame(0, $result->exitCode()->value);
        $this->assertSame(1, SmokeJobKernel::$bootCount);
        $this->assertSame(['j1', 'j2'], SmokeJobKernel::$handled);
    }

    public function testLauncherFailsWhenTransportMissing(): void
    {
        $result = (new JobWorkerLauncher())->runFromArgv(
            ['job-worker', '--kernel=' . SmokeJobKernelWithoutTransport::class],
            getcwd() ?: null,
        );

        $this->assertSame(WorkerStopReason::BootstrapFailure, $result->stopReason);
        $this->assertSame(WorkerExitCode::BootstrapFailure, $result->exitCode());
    }
}

final class SmokeJobKernel implements JobApplication
{
    public static int $bootCount = 0;

    /** @var list<string> */
    public static array $handled = [];

    private Container $container;
    private bool $booted = false;

    public function __construct()
    {
        $this->container = new Container();
        self::$bootCount = 0;
        self::$handled = [];
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        self::$bootCount++;

        $transport = new InMemoryJobTransport([
            new JobEnvelope('j1', 'one', null),
            new JobEnvelope('j2', 'two', null),
        ]);
        $this->container->singleton(JobTransport::class, $transport);
    }

    public function handle(JobEnvelope $job): JobResult
    {
        self::$handled[] = $job->id;

        return JobResult::ack();
    }

    public function getContainer(): Container
    {
        return $this->container;
    }
}

final class SmokeJobKernelWithoutTransport implements JobApplication
{
    private Container $container;

    public function __construct()
    {
        $this->container = new Container();
    }

    public function boot(): void {}

    public function handle(JobEnvelope $job): JobResult
    {
        return JobResult::ack();
    }

    public function getContainer(): Container
    {
        return $this->container;
    }
}
