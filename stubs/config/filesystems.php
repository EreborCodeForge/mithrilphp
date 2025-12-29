<?php

declare(strict_types=1);

use App\Core\Environment;

$basePath = rtrim(
    Environment::get('APP_BASE_PATH', dirname(__DIR__)),
    DIRECTORY_SEPARATOR
);

$appUrl = rtrim(Environment::get('APP_URL', 'http://localhost'), '/');

return [
    'default' => Environment::get('FILESYSTEM_DRIVER', 'local'),

    'disks' => [
        'local' => [
            'driver' => 'local',
            'root'   => Environment::get(
                'FILESYSTEM_LOCAL_ROOT',
                $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app'
            ),
        ],

        'public' => [
            'driver'     => 'local',
            'root'       => Environment::get(
                'FILESYSTEM_PUBLIC_ROOT',
                $basePath . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'storage'
            ),
            'url'        => $appUrl . '/storage',
            'visibility' => 'public',
        ],
    ],
];
