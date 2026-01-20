<?php

declare(strict_types=1);

namespace Erebor\Mithril\Http;

final class Request
{
    private readonly string $path;

    public function __construct(
        public readonly array $query,
        public readonly array $body,
        public readonly array $server,
        public readonly array $headers,
        public readonly array $cookies,
        public readonly array $files
    ) {
        $this->path = self::extractPath($this->getUri());
    }

    public static function createFromGlobals(): self
    {
        $body = [];
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (str_contains($contentType, 'application/json')) {
            $input = file_get_contents('php://input');
            $decoded = json_decode($input ?: '', true);
            $body = is_array($decoded) ? $decoded : [];
        } else {
            $body = $_POST;
        }

        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        if (!is_array($headers)) {
            $headers = [];
        }

        return new self(
            $_GET ?? [],
            $body,
            $_SERVER ?? [],
            $headers,
            $_COOKIE ?? [],
            self::processFiles($_FILES ?? [])
        );
    }

    private static function processFiles(array $files): array
    {
        $processed = [];

        foreach ($files as $key => $file) {
            if (!isset($file['name']) || is_array($file['name'])) {
                continue;
            }

            $processed[$key] = new UploadedFile(
                $file['name'],
                $file['type'] ?? '',
                $file['tmp_name'] ?? '',
                $file['error'] ?? UPLOAD_ERR_NO_FILE,
                $file['size'] ?? 0
            );
        }

        return $processed;
    }

    public function getMethod(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    public function getUri(): string
    {
        return $this->server['REQUEST_URI'] ?? '/';
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function header(string $key, mixed $default = null): mixed
    {
        if (isset($this->headers[$key])) {
            return $this->headers[$key];
        }

        $keyLower = strtolower($key);

        foreach ($this->headers as $k => $v) {
            if (strtolower((string) $k) === $keyLower) {
                return $v;
            }
        }

        return $default;
    }

    private static function extractPath(string $uri): string
    {
        $qPos = strpos($uri, '?');
        if ($qPos !== false) {
            $uri = substr($uri, 0, $qPos);
        }

        if ($uri === '') {
            return '/';
        }

        if ($uri[0] !== '/') {
            $path = parse_url($uri, PHP_URL_PATH);
            $uri = is_string($path) && $path !== '' ? $path : '/';
        }

        if ($uri !== '/' && str_ends_with($uri, '/')) {
            $uri = rtrim($uri, '/');
        }

        return $uri === '' ? '/' : $uri;
    }
}
