<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Eregion;

use Erebor\Mithril\Contracts\HttpApplication;

/**
 * Resolves application kernel class and artifact paths for Forge.
 */
final class ApplicationResolver
{
    public function __construct(
        private readonly string $workingDirectory,
    ) {}

    public function workingDirectory(): string
    {
        return $this->workingDirectory;
    }

    public function resolveKernelClass(): string
    {
        $fromEnv = getenv('MITHRIL_KERNEL');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        $composer = $this->workingDirectory . DIRECTORY_SEPARATOR . 'composer.json';
        if (is_file($composer)) {
            $data = json_decode((string) file_get_contents($composer), true);
            if (is_array($data)) {
                $kernel = $data['extra']['mithril']['kernel'] ?? null;
                if (is_string($kernel) && $kernel !== '') {
                    return $kernel;
                }
            }
        }

        return 'App\\Kernel';
    }

    public function autoloadPath(): string
    {
        return $this->workingDirectory . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    }

    public function compiledContainerPath(): string
    {
        return $this->workingDirectory . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'container.php';
    }

    public function compiledRoutesPath(): string
    {
        return $this->workingDirectory . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'routes.php';
    }

    public function manifestPath(): string
    {
        return $this->workingDirectory . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'eregion.json';
    }

    public function eregionConfigPath(): string
    {
        $yaml = $this->workingDirectory . DIRECTORY_SEPARATOR . 'eregion.yaml';
        if (is_file($yaml)) {
            return $yaml;
        }

        return $this->workingDirectory . DIRECTORY_SEPARATOR . 'eregion.yml';
    }

    public function eregionWorkerPath(): string
    {
        $candidates = [
            $this->workingDirectory . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'eregion-worker',
            dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'eregion-worker',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return $candidates[0];
    }

    public function assertKernelLoadable(): void
    {
        $autoload = $this->autoloadPath();
        if (!is_file($autoload)) {
            throw new Exceptions\ProtocolException("Composer autoload missing: {$autoload}");
        }

        require_once $autoload;

        $class = $this->resolveKernelClass();
        if (!class_exists($class)) {
            throw new Exceptions\ProtocolException("Kernel class not found: {$class}");
        }

        $ref = new \ReflectionClass($class);
        if (!$ref->implementsInterface(HttpApplication::class)) {
            throw new Exceptions\ProtocolException("Kernel must implement HttpApplication: {$class}");
        }
    }

    public function buildManifest(
        string $environment = 'production',
        int $maxRequests = 1000,
        int $memoryLimitBytes = 268435456,
    ): Manifest {
        $container = $this->compiledContainerPath();
        $routes = $this->compiledRoutesPath();

        return new Manifest(
            application: $this->resolveKernelClass(),
            autoload: $this->autoloadPath(),
            workingDirectory: $this->workingDirectory,
            compiledContainer: is_file($container) ? $container : null,
            compiledRoutes: is_file($routes) ? $routes : null,
            environment: $environment,
            worker: [
                'maxRequests' => $maxRequests,
                'memoryLimitBytes' => $memoryLimitBytes,
            ],
            protocol: [
                'version' => Protocol::VERSION,
                'maxFrameBytes' => Protocol::DEFAULT_MAX_FRAME_BYTES,
            ],
        );
    }
}
