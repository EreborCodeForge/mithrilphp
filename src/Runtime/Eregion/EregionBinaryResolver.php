<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Eregion;

/**
 * Resolves the Eregion binary path (env, PATH, or .mithril/bin).
 */
final class EregionBinaryResolver
{
    public function resolve(?string $workingDirectory = null): ?string
    {
        $env = getenv('EREGION_BINARY');
        if (is_string($env) && $env !== '' && $this->isExecutable($env)) {
            return $env;
        }

        $fromPath = $this->findInPath('eregion');
        if ($fromPath !== null) {
            return $fromPath;
        }

        $cwd = $workingDirectory ?? getcwd() ?: '.';
        $local = $this->localInstallPath($cwd);
        if ($this->isExecutable($local)) {
            return $local;
        }

        return null;
    }

    public function localInstallPath(string $workingDirectory): string
    {
        $base = rtrim($workingDirectory, '/\\')
            . DIRECTORY_SEPARATOR . '.mithril'
            . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'eregion';

        if (PHP_OS_FAMILY === 'Windows') {
            return $base . '.exe';
        }

        return $base;
    }

    public function isExecutable(string $path): bool
    {
        return is_file($path) && (is_executable($path) || PHP_OS_FAMILY === 'Windows');
    }

    private function findInPath(string $binary): ?string
    {
        $path = getenv('PATH');
        if (!is_string($path) || $path === '') {
            return null;
        }

        $separator = PHP_OS_FAMILY === 'Windows' ? ';' : ':';
        $extensions = PHP_OS_FAMILY === 'Windows' ? ['', '.exe', '.bat', '.cmd'] : [''];

        foreach (explode($separator, $path) as $dir) {
            if ($dir === '') {
                continue;
            }
            foreach ($extensions as $ext) {
                $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $binary . $ext;
                if ($this->isExecutable($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
