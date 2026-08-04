<?php

declare(strict_types=1);

namespace Erebor\Mithril\Console\Commands;

use Erebor\Mithril\Console\Command;
use Erebor\Mithril\Runtime\Eregion\EregionBinaryResolver;

final class ServerInstallCommand extends Command
{
    public static function getSignature(): string
    {
        return 'server:install';
    }

    public static function getDescription(): string
    {
        return 'Locate the Eregion binary (download not available yet)';
    }

    public function execute(): int
    {
        $cwd = getcwd() ?: '.';
        $resolver = new EregionBinaryResolver();
        $binary = $resolver->resolve($cwd);

        if ($binary !== null) {
            $this->info('Eregion binary found: ' . $binary);
            return 0;
        }

        $local = rtrim($cwd, '/\\') . DIRECTORY_SEPARATOR . '.mithril' . DIRECTORY_SEPARATOR . 'bin';
        $this->error('Eregion binary not found.');
        $this->line('');
        $this->line('Place the binary in one of:');
        $this->line('  1. Set EREGION_BINARY to an absolute path');
        $this->line('  2. Put `eregion` on your PATH');
        $this->line('  3. Copy it to ' . $local . DIRECTORY_SEPARATOR . 'eregion');
        $this->line('');
        $this->line('Automatic download is not implemented yet in this package version.');

        return 1;
    }
}
