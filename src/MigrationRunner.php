<?php

declare(strict_types=1);

namespace Erebor\Mithril;

use PDO;
use Exception;

class MigrationRunner
{
    private PDO $db;
    private string $migrationsPath;

    public function __construct(PDO $db, string $migrationsPath)
    {
        $this->db = $db;
        $this->migrationsPath = $migrationsPath;
        $this->ensureMigrationsTable();
    }

    private function ensureMigrationsTable(): void
    {
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $autoIncrement = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
        $timestamp = $driver === 'sqlite' ? 'DATETIME DEFAULT CURRENT_TIMESTAMP' : 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP';

        $this->db->exec("CREATE TABLE IF NOT EXISTS migrations (
            id $autoIncrement,
            migration VARCHAR(255),
            batch INT,
            created_at $timestamp
        )");
        
        // Check if batch column exists (for backward compatibility if table existed)
        try {
            $this->db->query("SELECT batch FROM migrations LIMIT 1");
        } catch (Exception $e) {
            $this->db->exec("ALTER TABLE migrations ADD COLUMN batch INT DEFAULT 1");
        }
    }

    public function migrate(): void
    {
        $applied = $this->getAppliedMigrations();
        $files = $this->getMigrationFiles();
        $pending = array_diff($files, $applied);

        if (empty($pending)) {
            echo "Nothing to migrate.\n";
            return;
        }

        $batch = $this->getNextBatch();
        echo "Running migrations (Batch $batch)...\n";

        foreach ($pending as $file) {
            $this->runUp($file, $batch);
        }
        
        echo "Migration completed.\n";
    }

    public function rollback(): void
    {
        $lastBatch = $this->getLastBatch();
        if ($lastBatch === 0) {
            echo "Nothing to rollback.\n";
            return;
        }

        echo "Rolling back batch $lastBatch...\n";

        $migrations = $this->getMigrationsByBatch($lastBatch);

        foreach ($migrations as $migration) {
            $this->runDown($migration);
        }

        echo "Rollback completed.\n";
    }

    public function fresh(): void
    {
        echo "Dropping all tables...\n";
        $this->dropAllTables();
        echo "Re-running migrations...\n";
        $this->ensureMigrationsTable();
        $this->migrate();
    }

    private function runUp(string $file, int $batch): void
    {
        $filePath = $this->migrationsPath . '/' . $file;
        $migration = require $filePath;

        echo "Migrating: $file\n";
        try {
            $migration->up();
            $stmt = $this->db->prepare("INSERT INTO migrations (migration, batch) VALUES (?, ?)");
            $stmt->execute([$file, $batch]);
            echo "Migrated: $file\n";
        } catch (Exception $e) {
            echo "Failed to migrate $file: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    private function runDown(string $file): void
    {
        $filePath = $this->migrationsPath . '/' . $file;
        if (file_exists($filePath)) {
            /** @var Migration $migration */
            $migration = require $filePath;

            echo "Rolling back: $file\n";
            try {
                $migration->down();
                $stmt = $this->db->prepare("DELETE FROM migrations WHERE migration = ?");
                $stmt->execute([$file]);
                echo "Rolled back: $file\n";
            } catch (Exception $e) {
                echo "Failed to rollback $file: " . $e->getMessage() . "\n";
                throw $e;
            }
        } else {
            echo "Migration file not found: $file (Removing record)\n";
            $stmt = $this->db->prepare("DELETE FROM migrations WHERE migration = ?");
            $stmt->execute([$file]);
        }
    }

    private function getAppliedMigrations(): array
    {
        return $this->db->query("SELECT migration FROM migrations")->fetchAll(PDO::FETCH_COLUMN);
    }

    private function getMigrationFiles(): array
    {
        $files = glob($this->migrationsPath . '/*.php');
        return array_map('basename', $files);
    }

    private function getNextBatch(): int
    {
        $stmt = $this->db->query("SELECT MAX(batch) FROM migrations");
        return (int)$stmt->fetchColumn() + 1;
    }

    private function getLastBatch(): int
    {
        $stmt = $this->db->query("SELECT MAX(batch) FROM migrations");
        return (int)$stmt->fetchColumn();
    }

    private function getMigrationsByBatch(int $batch): array
    {
        $stmt = $this->db->prepare("SELECT migration FROM migrations WHERE batch = ? ORDER BY id DESC");
        $stmt->execute([$batch]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function dropAllTables(): void
    {
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $this->db->exec("PRAGMA foreign_keys = OFF");
            $tables = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name != 'sqlite_sequence'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                $this->db->exec("DROP TABLE IF EXISTS \"$table\"");
            }
            $this->db->exec("PRAGMA foreign_keys = ON");
        } elseif ($driver === 'mysql') {
            $this->db->exec("SET FOREIGN_KEY_CHECKS = 0");
            $tables = $this->db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                $this->db->exec("DROP TABLE IF EXISTS `$table`");
            }
            $this->db->exec("SET FOREIGN_KEY_CHECKS = 1");
        }
    }
}
