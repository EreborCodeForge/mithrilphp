<?php

declare(strict_types=1);

use Erebor\Mithril\Container;
use Erebor\Mithril\Contracts\JobApplication;
use Erebor\Mithril\Jobs\InMemoryJobTransport;
use Erebor\Mithril\Jobs\JobTransport;
use Erebor\Mithril\Jobs\JobEnvelope;
use Erebor\Mithril\Jobs\JobResult;
use Erebor\Mithril\Runtime\JobWorker;
use Erebor\Mithril\Runtime\JobWorkerObserver;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$markerDir = $argv[1] ?? '';
if ($markerDir === '' || !is_dir($markerDir)) {
    fwrite(STDERR, "marker directory required\n");
    exit(20);
}

final class Eco04SignalApp implements JobApplication
{
    public function __construct(private readonly string $markerDir)
    {
    }

    private Container $container;

    private bool $booted = false;

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->container = new Container();
        $jobs = [];
        if (getenv('ECO04_BUSY') === '1') {
            $jobs[] = new JobEnvelope('slow', 'slow', null);
        }
        $this->container->singleton(JobTransport::class, new InMemoryJobTransport($jobs, idleWhenEmpty: true));
        $markerDir = $this->markerDir;
        $this->container->singleton(JobWorkerObserver::class, new class ($markerDir) implements JobWorkerObserver {
            public function __construct(private readonly string $markerDir)
            {
            }

            public function onBoot(): void
            {
            }

            public function onIdle(): void
            {
                if (!is_file($this->markerDir . '/ready')) {
                    file_put_contents($this->markerDir . '/ready', '1');
                }
            }

            public function onJobStarted(JobEnvelope $job): void
            {
            }

            public function onJobFinished(JobEnvelope $job, JobResult $result, float $duration): void
            {
            }

            public function onRetry(JobEnvelope $job): void
            {
            }

            public function onReject(JobEnvelope $job): void
            {
            }

            public function onRecycle(string $reason): void
            {
            }
        });
        $this->booted = true;
    }

    public function handle(JobEnvelope $job): JobResult
    {
        file_put_contents($this->markerDir . '/handling', '1');
        usleep(800000);
        file_put_contents($this->markerDir . '/finished', '1');

        return JobResult::ack();
    }

    public function getContainer(): Container
    {
        return $this->container;
    }
}

$app = new Eco04SignalApp($markerDir);
$result = (new JobWorker($app))->runResult();
file_put_contents($markerDir . '/exit', (string) $result->stopReason->value);
exit($result->exitCode()->value);
