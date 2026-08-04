<?php

declare(strict_types=1);

namespace Erebor\Mithril\Console\Commands;

use Erebor\Mithril\Console\Command;
use Erebor\Mithril\Runtime\Eregion\ApplicationResolver;
use Erebor\Mithril\Runtime\Eregion\EregionBinaryResolver;
use Erebor\Mithril\Runtime\Eregion\ServerHealthCheck;

final class ServerCheckCommand extends Command
{
    public static function getSignature(): string
    {
        return 'server:check';
    }

    public static function getDescription(): string
    {
        return 'Validate PHP runtime, extensions, kernel, artifacts, and Eregion binary';
    }

    public function execute(): int
    {
        $cwd = getcwd() ?: '.';
        $resolver = new ApplicationResolver($cwd);
        $checker = new ServerHealthCheck($resolver, new EregionBinaryResolver());
        $issues = $checker->check(requireBinary: false);

        $hasError = false;
        foreach ($issues as $issue) {
            $prefix = match ($issue['level']) {
                'ok' => '[ok]   ',
                'warning' => '[warn] ',
                default => '[fail] ',
            };
            $this->line($prefix . $issue['message']);
            if ($issue['level'] === 'error') {
                $hasError = true;
            }
        }

        return $hasError ? 1 : 0;
    }
}
