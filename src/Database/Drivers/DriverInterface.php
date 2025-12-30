<?php

declare(strict_types=1);

namespace Erebor\Mithril\Database\Drivers;

use PDO;

interface DriverInterface 
{
    public function buildDsn(array $config, bool $includeDatabase): string;
    public function createConnection(string $dsn, array $config): \PDO;
}