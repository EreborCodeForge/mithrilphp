<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Eregion;

/**
 * Resolves the Eregion binary path without downloading.
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
        $local = rtrim($cwd, '/\\') . DIRECTORY_SEPARATOR . '.mithril' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'eregion';
        if ($this->isExecutable($local)) {
            return $local;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $localExe = $local . '.exe';
            if ($this->isExecutable($localExe)) {
                return $localExe;
            }
        }

        return null;
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
