<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime;

use Erebor\Mithril\Contracts\JobApplication;
use Erebor\Mithril\Jobs\JobTransport;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Boots a JobApplication, resolves JobTransport from the container, runs JobWorker.
 */
final class JobWorkerLauncher
{
    /**
     * @param list<string> $argv
     */
    public function runFromArgv(array $argv, ?string $workingDirectory = null): WorkerResult
    {
        $cwd = $workingDirectory ?? getcwd();
        if (!is_string($cwd) || $cwd === '') {
            return new WorkerResult(0, WorkerStopReason::BootstrapFailure);
        }

        $kernelOverride = null;
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--kernel=')) {
                $kernelOverride = substr($arg, strlen('--kernel='));
                break;
            }
        }

        try {
            $resolver = new JobApplicationResolver($cwd, $kernelOverride);
            $resolver->assertKernelLoadable();
            $class = $resolver->resolveKernelClass();

            /** @var JobApplication $app */
            $app = new $class();
            if (!$app instanceof JobApplication) {
                throw new InvalidArgumentException("Job kernel must implement JobApplication: {$class}");
            }

            $app->boot();

            $container = $app->getContainer();
            if (!$container->has(JobTransport::class)) {
                throw new RuntimeException(
                    'JobTransport is not bound in the container. Bind ' . JobTransport::class . ' after boot.'
                );
            }

            $transport = $container->get(JobTransport::class);
            if (!$transport instanceof JobTransport) {
                throw new RuntimeException(
                    'Container binding for JobTransport must resolve to ' . JobTransport::class
                );
            }
        } catch (Throwable) {
            return new WorkerResult(0, WorkerStopReason::BootstrapFailure);
        }

        return (new JobWorker($app, $transport))->runResult();
    }
}
