<?php

declare(strict_types=1);

namespace App\Core\Logger;

use App\Core\Environment;
use RuntimeException;

class FileLogger implements LoggerInterface
{
    private string $logFile;

    public function __construct(?string $logFile)
    {
        $this->logFile = $logFile ?? __DIR__ . '/../../../logs/app.log';
        $this->ensureLogDirectoryExists();
    }

    private function ensureLogDirectoryExists(): void
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new RuntimeException(sprintf('Directory "%s" was not created', $dir));
            }
        }
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $date = date('Y-m-d H:i:s');
        $contextString = !empty($context) ? json_encode($context) : '';
        $logEntry = sprintf("[%s] %s: %s %s%s", $date, strtoupper($level), $message, $contextString, PHP_EOL);

        file_put_contents($this->logFile, $logEntry, FILE_APPEND);
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }
}
