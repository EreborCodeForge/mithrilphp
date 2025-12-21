<?php
declare(strict_types=1);

// Router script para php -S

$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Normaliza
$uriPath = '/' . ltrim($uriPath, '/');

// Se existe um arquivo real dentro de /public, deixa o built-in servir
$publicFile = __DIR__ . '/public' . $uriPath;

if ($uriPath !== '/' && is_file($publicFile)) {
    return false; // serve estático
}

// Caso contrário, cai no front controller
require __DIR__ . '/public/index.php';
