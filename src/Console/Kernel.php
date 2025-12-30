<?php

declare(strict_types=1);

namespace Erebor\Mithril\Console;

use Erebor\Mithril\Console\Commands\MigrateCommand;
use Erebor\Mithril\Console\Commands\ServeCommand;

final class Kernel
{
    private array $commands = [];

    public function __construct()
    {
        $this->register(ServeCommand::class);
        $this->register(\Erebor\Mithril\Console\Commands\LinkResourcesCommand::class);
    }

    public function register(string $commandClass): void
    {
        if (!is_subclass_of($commandClass, Command::class)) {
            throw new \InvalidArgumentException("Class $commandClass must extend " . Command::class);
        }
        $this->commands[$commandClass::getSignature()] = $commandClass;
    }

    public function handle(array $argv): int
    {
        $this->showLogo();

        $commandName = $argv[1] ?? 'help';

        if ($commandName === 'help') {
            $this->showHelp();
            return 0;
        }

        if (!isset($this->commands[$commandName])) {
            echo Color::red("Command \"$commandName\" not found.") . PHP_EOL;
            $this->showHelp();
            return 1;
        }

        $commandClass = $this->commands[$commandName];
        $args = array_slice($argv, 2);

        /** @var Command $command */
        $command = new $commandClass();

        if (method_exists($command, 'setArgs')) {
            $command->setArgs($args);
        }

        return $command->execute();
    }

    private function showHelp(): void
    {
        echo Color::yellow("Available commands:") . PHP_EOL;
        foreach ($this->commands as $signature => $class) {
            echo Color::green(str_pad($signature, 20)) . $class::getDescription() . PHP_EOL;
        }
    }

    private function showLogo(): void
    {
        $logo = <<<ASCII
     __    __     __     ______   __  __     ______     __     __        
    /\ "-./  \   /\ \   /\__  _\ /\ \_\ \   /\  == \   /\ \   /\ \       
    \ \ \-./\ \  \ \ \  \/_/\ \/ \ \  __ \  \ \  __<   \ \ \  \ \ \____  
     \ \_\ \ \_\  \ \_\    \ \_\  \ \_\ \_\  \ \_\ \_\  \ \_\  \ \_____\ 
      \/_/  \/_/   \/_/     \/_/   \/_/\/_/   \/_/ /_/   \/_/   \/_____/ PHP
    ASCII;

        echo Color::blue($logo) . PHP_EOL;
        echo Color::red(" \n Forged by EreborCodeForgee") . PHP_EOL . PHP_EOL;
    }

}
