<?php

declare(strict_types=1);

namespace Erebor\Mithril\Console\Commands;

use Erebor\Mithril\Console\ArgParser;
use Erebor\Mithril\Console\Command;
use Erebor\Mithril\Environment;
use Erebor\Mithril\Runtime\Eregion\ApplicationResolver;
use Erebor\Mithril\Runtime\Eregion\EregionBinaryResolver;
use Erebor\Mithril\Runtime\Eregion\Exceptions\ProtocolException;
use Erebor\Mithril\Runtime\Eregion\ServerHealthCheck;

final class ServeCommand extends Command
{
    public static function getSignature(): string
    {
        return 'serve';
    }

    public static function getDescription(): string
    {
        return 'Start the Eregion application server';
    }

    public function execute(): int
    {
        $parsed = ArgParser::parse($this->args);
        $cwd = getcwd() ?: '.';
        $resolver = new ApplicationResolver($cwd);
        $binaryResolver = new EregionBinaryResolver();
        $checker = new ServerHealthCheck($resolver, $binaryResolver);

        $host = ArgParser::string($parsed['options'], 'host', '0.0.0.0') ?? '0.0.0.0';
        $port = ArgParser::int($parsed['options'], 'port', (int) Environment::get('APP_PORT', '8080'));
        $workers = ArgParser::int($parsed['options'], 'workers', 0);

        try {
            $issues = $checker->check(requireBinary: true);
            $errors = array_filter($issues, static fn (array $i): bool => $i['level'] === 'error');
            if ($errors !== []) {
                foreach ($errors as $error) {
                    $this->error($error['message']);
                }
                return 1;
            }

            foreach ($issues as $issue) {
                if ($issue['level'] === 'warning') {
                    $this->line($issue['message']);
                }
            }

            $manifest = $resolver->buildManifest();
            $manifestPath = $resolver->manifestPath();
            $manifest->write($manifestPath);

            $binary = $binaryResolver->resolve($cwd);
            if ($binary === null) {
                $this->error('Eregion binary not found. Set EREGION_BINARY or run forge server:install.');
                return 1;
            }

            $configPath = $resolver->eregionConfigPath();
            $cmd = [$binary, 'serve', '--manifest=' . $manifestPath];
            if (is_file($configPath)) {
                $cmd[] = '--config=' . $configPath;
            }
            $cmd[] = '--host=' . $host;
            $cmd[] = '--port=' . (string) $port;
            if ($workers > 0) {
                $cmd[] = '--workers=' . (string) $workers;
            }

            $this->info("Starting Eregion via {$binary}");
            $this->line('Manifest: ' . $manifestPath);

            return $this->execReplace($cmd);
        } catch (ProtocolException $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }

    /**
     * @param list<string> $cmd
     */
    private function execReplace(array $cmd): int
    {
        if (function_exists('pcntl_exec')) {
            $binary = array_shift($cmd);
            pcntl_exec($binary, $cmd);
            // If pcntl_exec returns, it failed
            $this->error('pcntl_exec failed');
            return 1;
        }

        $escaped = array_map('escapeshellarg', $cmd);
        passthru(implode(' ', $escaped), $exitCode);

        return $exitCode;
    }
}
