<?php

declare(strict_types=1);

namespace Erebor\Mithril\Database;

use Erebor\Mithril\Database\Drivers\DriverInterface;
use Erebor\Mithril\Database\Drivers\MysqlDriver;
use Erebor\Mithril\Database\Drivers\PgsqlDriver;
use Erebor\Mithril\Database\Drivers\SqliteDriver;
use PDO;
use InvalidArgumentException;

final class ConnectionFactory
{
    private static array $drivers = [
        'mysql' => MysqlDriver::class,
        'pgsql' => PgsqlDriver::class,
        'sqlite' => SqliteDriver::class,
    ];

    public static function create(array $config): PDO
    {
        return self::createConnection($config, includeDatabase: true);
    }

    public static function createWithoutDatabase(array $config): PDO
    {
        return self::createConnection($config, includeDatabase: false);
    }

    public static function registerDriver(string $name, string $driverClass): void
    {
        if (!is_subclass_of($driverClass, DriverInterface::class)) {
            throw new InvalidArgumentException(
                "Driver must implement " . DriverInterface::class
            );
        }

        self::$drivers[$name] = $driverClass;
    }

    private static function createConnection(array $config, bool $includeDatabase): PDO
    {
        $driver = self::resolveDriver($config);
        $dsn = $driver->buildDsn($config, $includeDatabase);
        
        return $driver->createConnection($dsn, $config);
    }

    private static function resolveDriver(array $config): DriverInterface
    {
        $driverName = $config['driver'] ?? 'mysql';

        $driverClass = self::$drivers[$driverName] 
            ?? throw new InvalidArgumentException("Unsupported driver: {$driverName}");

        return new $driverClass();
    }
}