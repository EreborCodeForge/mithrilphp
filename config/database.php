<?php

declare(strict_types=1);

use App\Core\Environment;

$connection = Environment::get(key: 'DB_CONNECTION', default: 'mysql');

if ($connection === 'sqlite') {
    return [
        'driver' => 'sqlite',
        'database' => __DIR__ . '/../' . Environment::get('DB_FILE', 'database.sqlite'),
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    ];
}

return [
    'driver' => 'mysql',
    'host' => Environment::get('DB_HOST', 'localhost'),
    'dbname' => Environment::get('DB_DATABASE', 'appmarket'),
    'user' => Environment::get('DB_USERNAME', 'root'),
    'password' => Environment::get('DB_PASSWORD', ''),
    'charset' => 'utf8mb4',
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
];
