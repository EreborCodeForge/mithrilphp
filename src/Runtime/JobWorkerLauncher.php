<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime;

use Erebor\Mithril\Contracts\JobApplication;
use InvalidArgumentException;
use Throwable;

/**
 * Resolves a JobApplication from argv and runs JobWorker (single boot inside the worker loop).
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
        } catch (Throwable) {
            return new WorkerResult(0, WorkerStopReason::BootstrapFailure);
        }

        $metadata = EregionWorkloadMetadata::fromEnvironment();

        return (new JobWorker(
            app: $app,
            eregionMetadata: $metadata->isPresent() ? $metadata : null,
        ))->runResult();
    }
}
