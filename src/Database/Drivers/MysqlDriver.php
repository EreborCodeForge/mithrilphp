<?php

declare(strict_types=1);

namespace Erebor\Mithril\Database\Drivers;

use PDO;

final class MysqlDriver implements DriverInterface
{
    public function buildDsn(array $config, bool $includeDatabase = true): string
    {
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 3306;
        $charset = $config['charset'] ?? 'utf8mb4';
        
        $dsn = "mysql:host={$host};port={$port};charset={$charset}";
        
        if ($includeDatabase && !empty($config['dbname'])) {
            $dsn .= ";dbname={$config['dbname']}";
        }

        return $dsn;
    }

    public function createConnection(string $dsn, array $config): PDO
    {
        return new PDO(
            $dsn,
            $config['user'] ?? '',
            $config['password'] ?? '',
            $config['options'] ?? [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
}