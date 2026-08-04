<?php

declare(strict_types=1);

namespace Erebor\Mithril\Http;

final class Request
{
    private readonly string $path;

    /** @var array<string, list<string>> */
    public readonly array $headers;

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body Parsed form/JSON fields (not raw bytes)
     * @param array<string, mixed> $server
     * @param array<string, list<string>|string> $headers Multi-value headers
     * @param array<string, mixed> $cookies
     * @param array<string, UploadedFile> $files
     */
    public function __construct(
        public readonly array $query,
        public readonly array $body,
        public readonly array $server,
        array $headers,
        public readonly array $cookies,
        public readonly array $files,
        public readonly string $rawBody = '',
    ) {
        $this->headers = self::normalizeHeaders($headers);
        $this->path = self::extractPath($this->getUri());
    }

    public static function createFromGlobals(): self
    {
        $rawBody = file_get_contents('php://input') ?: '';
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $body = [];

        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($rawBody, true);
            $body = is_array($decoded) ? $decoded : [];
        } else {
            $body = $_POST;
        }

        $rawHeaders = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        if (!is_array($rawHeaders)) {
            $rawHeaders = [];
        }

        return new self(
            $_GET ?? [],
            $body,
            $_SERVER ?? [],
            self::normalizeHeaders($rawHeaders),
            $_COOKIE ?? [],
            self::processFiles($_FILES ?? []),
            $rawBody,
        );
    }

    /**
     * Build a request from transport-level data (e.g. Eregion envelope).
     *
     * @param array<string, list<string>|string> $headers
     * @param array<string, mixed> $server
     * @param array<string, mixed> $cookies
     * @param array<string, UploadedFile> $files
     */
    public static function create(
        string $method,
        string $uri,
        array $headers = [],
        string $rawBody = '',
        array $server = [],
        array $cookies = [],
        array $files = [],
        array $query = [],
        array $body = [],
    ): self {
        $server = array_merge([
            'REQUEST_METHOD' => strtoupper($method),
            'REQUEST_URI' => $uri,
        ], $server);

        if ($query === [] && str_contains($uri, '?')) {
            parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        }

        if ($body === [] && $rawBody !== '') {
            $contentType = self::firstHeaderValue($headers, 'Content-Type')
                ?? ($server['CONTENT_TYPE'] ?? '');
            if (str_contains((string) $contentType, 'application/json')) {
                $decoded = json_decode($rawBody, true);
                $body = is_array($decoded) ? $decoded : [];
            } elseif (str_contains((string) $contentType, 'application/x-www-form-urlencoded')) {
                parse_str($rawBody, $body);
            }
        }

        return new self(
            $query,
            $body,
            $server,
            self::normalizeHeaders($headers),
            $cookies,
            $files,
            $rawBody,
        );
    }

    /**
     * @param array<string, list<string>|string|mixed> $headers
     * @return array<string, list<string>>
     */
    public static function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $key = (string) $name;
            if (is_array($value)) {
                $normalized[$key] = array_values(array_map(static fn (mixed $v): string => (string) $v, $value));
            } else {
                $normalized[$key] = [(string) $value];
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, list<string>|string> $headers
     */
    private static function firstHeaderValue(array $headers, string $name): ?string
    {
        $target = strtolower($name);
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== $target) {
                continue;
            }
            if (is_array($value)) {
                return isset($value[0]) ? (string) $value[0] : null;
            }

            return (string) $value;
        }

        return null;
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
        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    public function getUri(): string
    {
        return (string) ($this->server['REQUEST_URI'] ?? '/');
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getRawBody(): string
    {
        return $this->rawBody;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    /**
     * First value for a header (case-insensitive), or default.
     */
    public function header(string $key, mixed $default = null): mixed
    {
        $values = $this->headerValues($key);
        if ($values === []) {
            return $default;
        }

        return $values[0];
    }

    /**
     * @return list<string>
     */
    public function headerValues(string $key): array
    {
        $keyLower = strtolower($key);

        foreach ($this->headers as $name => $values) {
            if (strtolower((string) $name) === $keyLower) {
                return $values;
            }
        }

        return [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function getHeaders(): array
    {
        return $this->headers;
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
