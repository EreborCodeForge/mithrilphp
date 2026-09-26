<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime;

use Erebor\Mithril\Contracts\JobApplication;
use InvalidArgumentException;
use RuntimeException;

/**
 * Resolves the job kernel class for bin/job-worker (no Eregion paths).
 */
final class JobApplicationResolver
{
    public function __construct(
        private readonly string $workingDirectory,
        private readonly ?string $kernelOverride = null,
    ) {}

    public function workingDirectory(): string
    {
        return $this->workingDirectory;
    }

    public function resolveKernelClass(): string
    {
        if (is_string($this->kernelOverride) && $this->kernelOverride !== '') {
            return $this->kernelOverride;
        }

        $fromEnv = getenv('MITHRIL_JOB_KERNEL');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        $composer = $this->workingDirectory . DIRECTORY_SEPARATOR . 'composer.json';
        if (is_file($composer)) {
            $data = json_decode((string) file_get_contents($composer), true);
            if (is_array($data)) {
                $kernel = $data['extra']['mithril']['job_kernel'] ?? null;
                if (is_string($kernel) && $kernel !== '') {
                    return $kernel;
                }
            }
        }

        return 'App\\JobKernel';
    }

    public function autoloadPath(): string
    {
        return $this->workingDirectory . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    }

    /**
     * @throws RuntimeException|InvalidArgumentException
     */
    public function assertKernelLoadable(): void
    {
        $class = $this->resolveKernelClass();

        if (!class_exists($class)) {
            throw new RuntimeException("Job kernel class not found: {$class}");
        }

        $ref = new \ReflectionClass($class);
        if (!$ref->implementsInterface(JobApplication::class)) {
            throw new InvalidArgumentException("Job kernel must implement JobApplication: {$class}");
        }
    }
}
