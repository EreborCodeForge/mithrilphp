<?php

declare(strict_types=1);

namespace Erebor\Mithril\Console;

abstract class Command
{
    protected array $args = [];

    public function setArgs(array $args): void
    {
        $this->args = $args;
    }

    abstract public function execute(): int;

    abstract public static function getSignature(): string;

    abstract public static function getDescription(): string;

    protected function info(string $message): void
    {
        echo Color::green($message) . PHP_EOL;
    }

    protected function error(string $message): void
    {
        echo Color::red($message) . PHP_EOL;
    }

    protected function line(string $message): void
    {
        echo $message . PHP_EOL;
    }
}
