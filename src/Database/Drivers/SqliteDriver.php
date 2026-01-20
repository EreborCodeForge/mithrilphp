<?php

declare(strict_types=1);

namespace Erebor\Mithril\Database\Drivers;

use PDO;

final class SqliteDriver implements DriverInterface
{
    public function buildDsn(array $config, bool $includeDatabase = true): string
    {
        return "sqlite:{$config['database']}";
    }

    public function createConnection(string $dsn, array $config): PDO
    {
        return new PDO(
            $dsn,
            null,
            null,
            $config['options'] ?? [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
}