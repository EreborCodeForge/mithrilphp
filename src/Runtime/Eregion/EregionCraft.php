<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Eregion;

/**
 * Scaffolds Eregion runtime files for an application directory.
 * Defaults for missing YAML keys remain the Go binary's responsibility;
 * this only writes a starter tree for DX.
 */
final class EregionCraft
{
    public function __construct(
        private readonly ApplicationResolver $resolver,
    ) {}

    /**
     * @return list<array{path: string, action: string}>
     */
    public function craft(bool $force = false): array
    {
        $dir = $this->resolver->workingDirectory();
        if (!is_dir($dir)) {
            throw new Exceptions\ProtocolException("Directory does not exist: {$dir}");
        }

        $real = realpath($dir);
        if ($real === false) {
            throw new Exceptions\ProtocolException("Unable to resolve directory: {$dir}");
        }

        $actions = [];
        $actions[] = $this->ensureDirectory($real . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'runtime');
        $actions[] = $this->ensureDirectory($real . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache');

        $yamlPath = $real . DIRECTORY_SEPARATOR . 'eregion.yaml';
        $actions[] = $this->writeFile($yamlPath, $this->yamlStarter(), $force);

        $environment = getenv('APP_ENV');
        $environment = is_string($environment) && $environment !== '' ? $environment : 'production';

        $manifest = $this->resolver->buildManifest(environment: $environment);
        $manifestPath = $this->resolver->manifestPath();
        $actions[] = $this->writeFile(
            $manifestPath,
            json_encode($manifest->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            $force,
        );

        return array_values(array_filter($actions));
    }

    /**
     * @return array{path: string, action: string}
     */
    private function ensureDirectory(string $path): array
    {
        if (is_dir($path)) {
            return ['path' => $path, 'action' => 'exists'];
        }

        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            throw new Exceptions\ProtocolException("Unable to create directory: {$path}");
        }

        return ['path' => $path, 'action' => 'created'];
    }

    /**
     * @return array{path: string, action: string}
     */
    private function writeFile(string $path, string $contents, bool $force): array
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new Exceptions\ProtocolException("Unable to create directory: {$dir}");
        }

        if (is_file($path) && !$force) {
            return ['path' => $path, 'action' => 'skipped'];
        }

        $existed = is_file($path);

        if (file_put_contents($path, $contents) === false) {
            throw new Exceptions\ProtocolException("Unable to write: {$path}");
        }

        return ['path' => $path, 'action' => $existed ? 'overwritten' : 'created'];
    }

    private function yamlStarter(): string
    {
        // Starter only — omitted keys use Eregion (Go) runtime defaults.
        return <<<'YAML'
# Eregion Application Server — starter config (DX scaffold)
# Canonical defaults live in the Eregion binary; omit keys to use them.

server:
  host: 0.0.0.0
  port: 8080

workers:
  count: 4

worker:
  max_requests: 1000
  memory_limit_mb: 256

socket:
  directory: var/runtime

YAML;
    }
}
