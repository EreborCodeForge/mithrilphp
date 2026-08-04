<?php

declare(strict_types=1);

namespace Erebor\Mithril\Console\Commands;

use Erebor\Mithril\Console\ArgParser;
use Erebor\Mithril\Console\Command;
use Erebor\Mithril\Environment;

final class ServePhpCommand extends Command
{
    public static function getSignature(): string
    {
        return 'serve:php';
    }

    public static function getDescription(): string
    {
        return 'Start the built-in PHP development server (php -S)';
    }

    public function execute(): int
    {
        $parsed = ArgParser::parse($this->args);
        $host = ArgParser::string($parsed['options'], 'host', 'localhost') ?? 'localhost';
        $port = ArgParser::string($parsed['options'], 'port', (string) Environment::get('APP_PORT', '8000')) ?? '8000';

        $this->info("Starting development server at http://{$host}:{$port}");
        $this->line('Press Ctrl+C to stop.');

        passthru('php -S ' . escapeshellarg($host . ':' . $port) . ' -t public', $exitCode);

        return $exitCode;
    }
}
