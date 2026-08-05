<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Eregion;

/**
 * Pinned Eregion release metadata for Forge installs.
 */
final class EregionRelease
{
    public const DEFAULT_REPO = 'EreborCodeForge/eregion';

    public const DEFAULT_VERSION = 'v0.1.0';

    public const PROTOCOL = 'eregion/1';

    public static function resolveVersion(?string $workingDirectory = null, ?string $cliVersion = null): string
    {
        if (is_string($cliVersion) && $cliVersion !== '') {
            return self::normalizeVersion($cliVersion);
        }

        $fromEnv = getenv('EREGION_VERSION');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return self::normalizeVersion($fromEnv);
        }

        $cwd = $workingDirectory ?? getcwd() ?: '.';
        $composer = rtrim($cwd, '/\\') . DIRECTORY_SEPARATOR . 'composer.json';
        if (is_file($composer)) {
            $data = json_decode((string) file_get_contents($composer), true);
            $pinned = $data['extra']['mithril']['eregion'] ?? null;
            if (is_string($pinned) && $pinned !== '') {
                return self::normalizeVersion($pinned);
            }
        }

        return self::DEFAULT_VERSION;
    }

    public static function resolveRepo(?string $workingDirectory = null): string
    {
        $fromEnv = getenv('EREGION_REPO');
        if (is_string($fromEnv) && $fromEnv !== '' && str_contains($fromEnv, '/')) {
            return $fromEnv;
        }

        $cwd = $workingDirectory ?? getcwd() ?: '.';
        $composer = rtrim($cwd, '/\\') . DIRECTORY_SEPARATOR . 'composer.json';
        if (is_file($composer)) {
            $data = json_decode((string) file_get_contents($composer), true);
            $repo = $data['extra']['mithril']['eregion_repo'] ?? null;
            if (is_string($repo) && $repo !== '' && str_contains($repo, '/')) {
                return $repo;
            }
        }

        return self::DEFAULT_REPO;
    }

    public static function normalizeVersion(string $version): string
    {
        $version = trim($version);
        if ($version === '') {
            return self::DEFAULT_VERSION;
        }

        return str_starts_with($version, 'v') ? $version : 'v' . $version;
    }

    public static function assetName(?string $osFamily = null, ?string $machine = null): string
    {
        $os = strtolower($osFamily ?? PHP_OS_FAMILY);
        $arch = self::normalizeArch($machine ?? php_uname('m'));

        $osKey = match ($os) {
            'windows' => 'windows',
            'darwin' => 'darwin',
            'linux' => 'linux',
            default => throw new Exceptions\ProtocolException("Unsupported OS for Eregion binary: {$os}"),
        };

        $name = "eregion-{$osKey}-{$arch}";
        if ($osKey === 'windows') {
            $name .= '.exe';
        }

        return $name;
    }

    public static function normalizeArch(string $machine): string
    {
        $m = strtolower($machine);

        return match (true) {
            str_contains($m, 'aarch64'), str_contains($m, 'arm64') => 'arm64',
            str_contains($m, 'x86_64'), str_contains($m, 'amd64'), $m === 'x64' => 'amd64',
            default => throw new Exceptions\ProtocolException("Unsupported architecture for Eregion binary: {$machine}"),
        };
    }

    public static function downloadUrl(string $repo, string $version, string $asset): string
    {
        $version = self::normalizeVersion($version);

        return "https://github.com/{$repo}/releases/download/{$version}/{$asset}";
    }
}
