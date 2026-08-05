<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Eregion;

/**
 * Downloads a pinned Eregion release asset, verifies SHA-256, installs under .mithril/bin.
 */
final class EregionInstaller
{
    public function __construct(
        private readonly EregionBinaryResolver $resolver = new EregionBinaryResolver(),
        private readonly ?\Closure $downloader = null,
    ) {}

    /**
     * @return array{path: string, version: string, asset: string, action: string}
     */
    public function install(
        string $workingDirectory,
        ?string $version = null,
        bool $force = false,
        ?string $repo = null,
    ): array {
        $version = EregionRelease::resolveVersion($workingDirectory, $version);
        $repo = $repo ?? EregionRelease::resolveRepo($workingDirectory);
        $asset = EregionRelease::assetName();
        $target = $this->resolver->localInstallPath($workingDirectory);

        if (!$force && $this->resolver->isExecutable($target)) {
            return [
                'path' => $target,
                'version' => $version,
                'asset' => $asset,
                'action' => 'exists',
            ];
        }

        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new Exceptions\ProtocolException("Unable to create directory: {$dir}");
        }

        $checksumUrl = EregionRelease::downloadUrl($repo, $version, 'checksums.txt');
        $binaryUrl = EregionRelease::downloadUrl($repo, $version, $asset);

        $checksumsRaw = $this->download($checksumUrl);
        $expected = $this->expectedChecksum($checksumsRaw, $asset);
        if ($expected === null) {
            throw new Exceptions\ProtocolException("Asset \"{$asset}\" not listed in checksums.txt for {$version}");
        }

        $binary = $this->download($binaryUrl);
        $actual = hash('sha256', $binary);
        if (!hash_equals($expected, $actual)) {
            throw new Exceptions\ProtocolException(
                "SHA-256 mismatch for {$asset}: expected {$expected}, got {$actual}"
            );
        }

        $tmp = $target . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $binary) === false) {
            throw new Exceptions\ProtocolException("Unable to write temporary binary: {$tmp}");
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            @chmod($tmp, 0755);
        }

        if (is_file($target) && !@unlink($target) && PHP_OS_FAMILY === 'Windows') {
            // Windows may lock; try replace via rename after unlink failure
        }

        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new Exceptions\ProtocolException("Unable to install binary to {$target}");
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            @chmod($target, 0755);
        }

        if (!$this->resolver->isExecutable($target)) {
            throw new Exceptions\ProtocolException("Installed file is not executable: {$target}");
        }

        return [
            'path' => $target,
            'version' => $version,
            'asset' => $asset,
            'action' => 'installed',
        ];
    }

    /**
     * @return array<string, string> asset => sha256
     */
    public function parseChecksums(string $contents): array
    {
        $map = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!preg_match('/^([a-fA-F0-9]{64})\s+(\S+)$/', $line, $m)) {
                continue;
            }

            $map[$m[2]] = strtolower($m[1]);
        }

        return $map;
    }

    private function expectedChecksum(string $checksumsRaw, string $asset): ?string
    {
        $map = $this->parseChecksums($checksumsRaw);

        return $map[$asset] ?? null;
    }

    private function download(string $url): string
    {
        if ($this->downloader !== null) {
            $body = ($this->downloader)($url);
            if (!is_string($body) || $body === '') {
                throw new Exceptions\ProtocolException("Empty download for {$url}");
            }

            return $body;
        }

        if (!ini_get('allow_url_fopen')) {
            throw new Exceptions\ProtocolException('allow_url_fopen is disabled; cannot download Eregion binary');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 120,
                'header' => "User-Agent: mithrilphp-forge\r\nAccept: */*\r\n",
                'follow_location' => 1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false || $body === '') {
            throw new Exceptions\ProtocolException("Failed to download: {$url}");
        }

        return $body;
    }
}
