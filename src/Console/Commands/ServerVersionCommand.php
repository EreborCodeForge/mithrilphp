<?php

declare(strict_types=1);

namespace Erebor\Mithril\Console\Commands;

use Erebor\Mithril\Console\Command;
use Erebor\Mithril\Runtime\Eregion\EregionBinaryResolver;
use Erebor\Mithril\Runtime\Eregion\EregionBinaryVersionInspector;
use Erebor\Mithril\Runtime\Eregion\EregionRelease;
use Erebor\Mithril\Runtime\Eregion\Exceptions\EregionBinaryException;

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
        $resolver = new EregionBinaryResolver();

        try {
            $binary = $resolver->resolve($cwd);
        } catch (EregionBinaryException $e) {
            $this->error($e->getMessage());
            return 1;
        }

        if ($binary === null) {
            $this->error('Eregion binary not found. Run: forge server:install');
            return 1;
        }

        $this->line('Binary: ' . $binary);

        try {
            $info = (new EregionBinaryVersionInspector())->inspect($binary);
        } catch (EregionBinaryException $e) {
            $this->error($e->getMessage());
            return 1;
        }

        $this->line('eregion ' . EregionRelease::versionCore($info['version']));
        $this->line('protocol ' . $info['protocol']);

        $required = EregionRelease::resolveVersion($cwd);
        if (EregionRelease::compareVersions($info['version'], $required) !== 0) {
            $this->error(
                'Eregion version mismatch: required ' . $required . ', installed ' . $info['version']
            );
            return 1;
        }

        return 0;
    }
}
