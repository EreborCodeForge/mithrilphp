<?php

declare(strict_types=1);

use App\Core\Environment;

$basePath = rtrim(
    Environment::get('APP_BASE_PATH', dirname(__DIR__)),
    DIRECTORY_SEPARATOR
);

$connection = Environment::get('DB_CONNECTION', 'mysql');

$pdoOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

if ($connection === 'sqlite') {
    $dbFile = Environment::get('DB_FILE', 'database.sqlite');

    // Se vier caminho relativo, resolve a partir do root do projeto
    $dbPath = str_starts_with($dbFile, DIRECTORY_SEPARATOR) || preg_match('#^[A-Za-z]:[\\\\/]#', $dbFile)
        ? $dbFile
        : $basePath . DIRECTORY_SEPARATOR . ltrim($dbFile, DIRECTORY_SEPARATOR);

    return [
        'driver'   => 'sqlite',
        'database' => $dbPath,
        'options'  => $pdoOptions,
    ];
}

return [
    'driver'      => 'mysql',
    'host'        => Environment::get('DB_HOST', 'localhost'),
    'port'        => (int) Environment::get('DB_PORT', '3306'),
    'unix_socket' => Environment::get('DB_SOCKET', null),
    'dbname'      => Environment::get('DB_DATABASE', 'appmarket'),
    'user'        => Environment::get('DB_USERNAME', 'root'),
    'password'    => Environment::get('DB_PASSWORD', ''),
    'charset'     => Environment::get('DB_CHARSET', 'utf8mb4'),
    'collation'   => Environment::get('DB_COLLATION', 'utf8mb4_unicode_ci'),
    'options'     => $pdoOptions,
];
