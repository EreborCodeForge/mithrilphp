<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Eregion;

final class ServerHealthCheck
{
    public function __construct(
        private readonly ApplicationResolver $resolver,
        private readonly EregionBinaryResolver $binaryResolver = new EregionBinaryResolver(),
    ) {}

    /**
     * @return list<array{level: string, message: string}>
     */
    public function check(bool $requireBinary = false): array
    {
        $issues = [];

        if (PHP_VERSION_ID < 80300) {
            $issues[] = ['level' => 'error', 'message' => 'PHP 8.3+ is required (found ' . PHP_VERSION . ')'];
        } else {
            $issues[] = ['level' => 'ok', 'message' => 'PHP ' . PHP_VERSION];
        }

        if (!extension_loaded('msgpack')) {
            $issues[] = ['level' => 'error', 'message' => 'ext-msgpack is required'];
        } else {
            $issues[] = ['level' => 'ok', 'message' => 'ext-msgpack loaded'];
        }

        if (!extension_loaded('sockets')) {
            $issues[] = ['level' => 'error', 'message' => 'ext-sockets is required'];
        } else {
            $issues[] = ['level' => 'ok', 'message' => 'ext-sockets loaded'];
        }

        $autoload = $this->resolver->autoloadPath();
        if (!is_file($autoload)) {
            $issues[] = ['level' => 'error', 'message' => "Autoload missing: {$autoload}"];
        }

        try {
            $this->resolver->assertKernelLoadable();
            $issues[] = ['level' => 'ok', 'message' => 'Kernel OK: ' . $this->resolver->resolveKernelClass()];
        } catch (Exceptions\ProtocolException $e) {
            $issues[] = ['level' => 'error', 'message' => $e->getMessage()];
        }

        $container = $this->resolver->compiledContainerPath();
        if (!is_file($container)) {
            $issues[] = ['level' => 'warning', 'message' => "Compiled container not found: {$container}"];
        } else {
            $issues[] = ['level' => 'ok', 'message' => 'Compiled container present'];
        }

        $routes = $this->resolver->compiledRoutesPath();
        if (!is_file($routes)) {
            $issues[] = ['level' => 'warning', 'message' => "Compiled routes not found: {$routes}"];
        } else {
            $issues[] = ['level' => 'ok', 'message' => 'Compiled routes present'];
        }

        $worker = $this->resolver->eregionWorkerPath();
        if (!is_file($worker)) {
            $issues[] = ['level' => 'error', 'message' => "eregion-worker entrypoint missing: {$worker}"];
        } else {
            $issues[] = ['level' => 'ok', 'message' => 'eregion-worker present'];
        }

        $socketDir = $this->resolver->workingDirectory() . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'runtime';
        if (!is_dir($socketDir)) {
            @mkdir($socketDir, 0755, true);
        }
        if (!is_dir($socketDir) || !is_writable($socketDir)) {
            $issues[] = ['level' => 'error', 'message' => "Runtime directory not writable: {$socketDir}"];
        } else {
            $issues[] = ['level' => 'ok', 'message' => 'Runtime directory writable'];
        }

        $binary = $this->binaryResolver->resolve($this->resolver->workingDirectory());
        if ($binary === null) {
            $issues[] = [
                'level' => $requireBinary ? 'error' : 'warning',
                'message' => 'Eregion binary not found (EREGION_BINARY, PATH, or .mithril/bin/eregion)',
            ];
        } else {
            $issues[] = ['level' => 'ok', 'message' => 'Eregion binary: ' . $binary];
        }

        $config = $this->resolver->eregionConfigPath();
        if (!is_file($config)) {
            $issues[] = ['level' => 'warning', 'message' => 'eregion.yaml not found (Eregion defaults will be used)'];
        } else {
            $issues[] = ['level' => 'ok', 'message' => 'Config present: ' . $config];
        }

        return $issues;
    }
}
