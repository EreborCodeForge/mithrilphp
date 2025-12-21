<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    private function __construct() {}

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $config = require __DIR__ . '/../../config/database.php';

            if ($config['driver'] === 'sqlite') {
                $dsn = "sqlite:{$config['database']}";
                self::$instance = new PDO($dsn, null, null, $config['options']);
            } else {
                $dsn = "mysql:host={$config['host']};charset={$config['charset']}";
                
                // Connect first, then select DB (or include in DSN)
                // In config/database.php, 'dbname' is provided.
                // Usually we include it in DSN.
                
                try {
                    $dsnWithDb = $dsn . ";dbname={$config['dbname']}";
                    self::$instance = new PDO($dsnWithDb, $config['user'], $config['password'], $config['options']);
                } catch (PDOException $e) {
                     // Handle missing DB for creation?
                     // If we are running migration to create DB, we might fail here.
                     // But typically Database::getConnection assumes DB exists for app usage.
                     // However, BaseMigrateCommand uses it.
                     
                     // Let's replicate what was in the forge script / index.php previously or the likely implementation.
                     // The previous implementation (in index.php I wrote) had a try-catch for 1049.
                     // The original Database class probably did too if it was robust, or maybe not.
                     // Let's assume a standard connection.
                     
                     if ($e->getCode() == 1049) {
                        // Unknown database
                        self::$instance = new PDO($dsn, $config['user'], $config['password'], $config['options']);
                     } else {
                        throw $e;
                     }
                }
            }
        }

        return self::$instance;
    }
}
