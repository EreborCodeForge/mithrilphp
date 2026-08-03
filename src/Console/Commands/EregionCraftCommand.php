<?php

declare(strict_types=1);

namespace Erebor\Mithril\Console\Commands;

use Erebor\Mithril\Console\ArgParser;
use Erebor\Mithril\Console\Command;
use Erebor\Mithril\Runtime\Eregion\ApplicationResolver;
use Erebor\Mithril\Runtime\Eregion\EregionCraft;
use Erebor\Mithril\Runtime\Eregion\Exceptions\ProtocolException;

final class EregionCraftCommand extends Command
{
    public static function getSignature(): string
    {
        return 'eregion:craft';
    }

    public static function getDescription(): string
    {
        return 'Scaffold eregion.yaml and var/runtime/eregion.json for the app';
    }

    public function execute(): int
    {
        $parsed = ArgParser::parse($this->args);
        $force = ($parsed['options']['force'] ?? false) === true
            || ArgParser::string($parsed['options'], 'force') === 'true';

        $dir = ArgParser::string($parsed['options'], 'dir');
        if ($dir === null || $dir === '') {
            $dir = getcwd() ?: '.';
        }

        $real = realpath($dir);
        if ($real === false || !is_dir($real)) {
            $this->error("Directory does not exist: {$dir}");
            return 1;
        }

        try {
            $craft = new EregionCraft(new ApplicationResolver($real));
            $actions = $craft->craft(force: $force);
        } catch (ProtocolException $e) {
            $this->error($e->getMessage());
            return 1;
        }

        $this->info('Eregion craft complete: ' . $real);
        foreach ($actions as $item) {
            $this->line(str_pad($item['action'], 11) . ' ' . $item['path']);
        }

        return 0;
    }
}
