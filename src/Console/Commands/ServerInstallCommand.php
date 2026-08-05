<?php

declare(strict_types=1);

namespace Erebor\Mithril\Console\Commands;

use Erebor\Mithril\Console\ArgParser;
use Erebor\Mithril\Console\Command;
use Erebor\Mithril\Runtime\Eregion\EregionBinaryResolver;
use Erebor\Mithril\Runtime\Eregion\EregionInstaller;
use Erebor\Mithril\Runtime\Eregion\EregionRelease;
use Erebor\Mithril\Runtime\Eregion\Exceptions\ProtocolException;

final class ServerInstallCommand extends Command
{
    public static function getSignature(): string
    {
        return 'server:install';
    }

    public static function getDescription(): string
    {
        return 'Download and verify the pinned Eregion binary into .mithril/bin';
    }

    public function execute(): int
    {
        $parsed = ArgParser::parse($this->args);
        $force = ($parsed['options']['force'] ?? false) === true;
        $version = ArgParser::string($parsed['options'], 'version');
        $cwd = getcwd() ?: '.';

        $resolver = new EregionBinaryResolver();
        $existing = $resolver->resolve($cwd);

        if ($existing !== null && !$force) {
            // Prefer reporting local install path semantics when already present anywhere
            $this->info('Eregion binary already available: ' . $existing);
            $this->line('Use --force to re-download the pinned release into .mithril/bin.');
            $this->line('Pinned version: ' . EregionRelease::resolveVersion($cwd, $version));
            return 0;
        }

        try {
            $result = (new EregionInstaller($resolver))->install(
                workingDirectory: $cwd,
                version: $version,
                force: true,
            );
        } catch (ProtocolException $e) {
            $this->error($e->getMessage());
            $this->line('');
            $this->line('Manual fallback:');
            $this->line('  1. Set EREGION_BINARY to an absolute path');
            $this->line('  2. Put `eregion` on your PATH');
            $this->line('  3. Place the asset in ' . dirname($resolver->localInstallPath($cwd)));
            return 1;
        }

        $this->info('Eregion ' . $result['action'] . ': ' . $result['path']);
        $this->line('Version: ' . $result['version']);
        $this->line('Asset:   ' . $result['asset']);
        $this->line('Protocol: ' . EregionRelease::PROTOCOL);

        return 0;
    }
}
