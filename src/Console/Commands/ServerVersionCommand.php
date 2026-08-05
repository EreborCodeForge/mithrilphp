<?php

declare(strict_types=1);

namespace Erebor\Mithril\Console\Commands;

use Erebor\Mithril\Console\Command;
use Erebor\Mithril\Runtime\Eregion\EregionBinaryResolver;

final class ServerVersionCommand extends Command
{
    public static function getSignature(): string
    {
        return 'server:version';
    }

    public static function getDescription(): string
    {
        return 'Show the resolved Eregion binary version';
    }

    public function execute(): int
    {
        $cwd = getcwd() ?: '.';
        $binary = (new EregionBinaryResolver())->resolve($cwd);

        if ($binary === null) {
            $this->error('Eregion binary not found. Run: forge server:install');
            return 1;
        }

        $this->line('Binary: ' . $binary);
        passthru(escapeshellarg($binary) . ' version', $exitCode);
        if ($exitCode !== 0) {
            passthru(escapeshellarg($binary) . ' --version', $exitCode);
        }

        return $exitCode;
    }
}
