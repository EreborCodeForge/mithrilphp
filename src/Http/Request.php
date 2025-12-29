<?php

declare(strict_types=1);

namespace Erebor\Mithril\Http;

class Request
{
    public function __construct(
        public readonly array $query,
        public readonly array $body,
        public readonly array $server,
        public readonly array $headers,
        public readonly array $cookies,
        public readonly array $files
    ) {}

    public static function createFromGlobals(): self
    {
        $body = [];
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (str_contains($contentType, 'application/json')) {
            $input = file_get_contents('php://input');
            $body = json_decode($input, true) ?? [];
        } else {
            $body = $_POST;
        }

        return new self(
            $_GET,
            $body,
            $_SERVER,
            getallheaders(),
            $_COOKIE,
            self::processFiles($_FILES)
        );
    }

    private static function processFiles(array $files): array
    {
        $processed = [];
        foreach ($files as $key => $file) {
            if (is_array($file['name'])) {
                // Handle multiple file uploads (not implemented for simplicity, but good to have a placeholder)
                // For now, let's assume single file uploads per key for this simple implementation
                continue; 
            }
            
            $processed[$key] = new UploadedFile(
                $file['name'],
                $file['type'],
                $file['tmp_name'],
                $file['error'],
                $file['size']
            );
        }
        return $processed;
    }

    public function getMethod(): string
    {
        return $this->server['REQUEST_METHOD'] ?? 'GET';
    }

    public function getUri(): string
    {
        return $this->server['REQUEST_URI'] ?? '/';
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function header(string $key, mixed $default = null): mixed
    {
        // Headers are often case-insensitive, but getallheaders returns them as is.
        // For simplicity, we'll do a case-insensitive search if direct access fails.
        if (isset($this->headers[$key])) {
            return $this->headers[$key];
        }

        $keyLower = strtolower($key);
        foreach ($this->headers as $k => $v) {
            if (strtolower($k) === $keyLower) {
                return $v;
            }
        }

        return $default;
    }
}
