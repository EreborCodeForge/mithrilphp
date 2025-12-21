<?php

declare(strict_types=1);

namespace App\Core;

abstract class Migration
{
    protected \PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    abstract public function up(): void;
    abstract public function down(): void;
}
