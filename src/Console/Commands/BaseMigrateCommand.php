<?php

declare(strict_types=1);

namespace Erebor\Mithril\Console\Commands;

use Erebor\Mithril\Core\Console\Command;
use Erebor\Mithril\Core\Database;
use Erebor\Mithril\Core\Environment;
use Erebor\Mithril\Core\MigrationRunner;
use PDO;
use PDOException;

abstract class BaseMigrateCommand extends Command
{
    protected function getRunner(): MigrationRunner
    {
        $this->ensureDatabaseExists();
        $db = Database::getConnection();
        return new MigrationRunner($db, __DIR__ . '/../../../migrations');
    }

    protected function ensureDatabaseExists(): void
    {
        $driver = Environment::get('DB_CONNECTION', 'mysql');

        try {
            if ($driver === 'mysql') {
                $host = Environment::get('DB_HOST', 'localhost');
                $user = Environment::get('DB_USERNAME', 'root');
                $pass = Environment::get('DB_PASSWORD', '');
                $dbname = Environment::get('DB_DATABASE', 'appmarket');
                $charset = 'utf8mb4';

                // Connect without DB selected to create it
                $dsn = "mysql:host=$host;charset=$charset";
                $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET $charset COLLATE utf8mb4_unicode_ci");
                
            } elseif ($driver === 'sqlite') {
                $dbFile = __DIR__ . '/../../../' . Environment::get('DB_FILE', 'database.sqlite');
                if (!file_exists($dbFile)) {
                    touch($dbFile);
                    $this->info("Created SQLite database at $dbFile");
                }
            }
        } catch (PDOException $e) {
            $this->error("DB Connection/Creation failed: " . $e->getMessage());
            exit(1);
        }
    }
}
