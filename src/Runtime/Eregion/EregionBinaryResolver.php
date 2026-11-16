<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Eregion;

use Erebor\Mithril\Runtime\Eregion\Exceptions\EregionBinaryException;

/**
 * Resolves one Eregion executable.
 *
 * Precedence: EREGION_BINARY, then .mithril/bin/eregion, then PATH.
 * An explicit override that is not executable fails with no fallback.
 */
final class EregionBinaryResolver
{
    public function resolve(?string $workingDirectory = null): ?string
    {
        $located = $this->locate($workingDirectory);

        return $located['path'] ?? null;
    }

    /**
     * @return array{path: string, origin: 'environment'|'project'|'path'}|null
     */
    public function locate(?string $workingDirectory = null): ?array
    {
        $env = getenv('EREGION_BINARY');
        if (is_string($env) && $env !== '') {
            if (!$this->isExecutable($env)) {
                throw new EregionBinaryException(
                    'EREGION_BINARY is set but is not an executable file: ' . $env
                    . '. Fix that path or unset EREGION_BINARY; no other binary will be used.'
                );
            }

            return ['path' => $env, 'origin' => 'environment'];
        }

        $cwd = $workingDirectory ?? getcwd() ?: '.';
        $local = $this->localInstallPath($cwd);
        if ($this->isExecutable($local)) {
            return ['path' => $local, 'origin' => 'project'];
        }

        $fromPath = $this->findInPath('eregion');
        if ($fromPath !== null) {
            return ['path' => $fromPath, 'origin' => 'path'];
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
