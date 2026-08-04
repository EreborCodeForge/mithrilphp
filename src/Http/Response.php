<?php

declare(strict_types=1);

namespace Erebor\Mithril\Http;

class Response
{
    /** @var array<string, list<string>> */
    private array $headers;

    /**
     * @param array<string, list<string>|string> $headers
     */
    public function __construct(
        private mixed $content = '',
        private int $statusCode = 200,
        array $headers = []
    ) {
        $this->headers = Request::normalizeHeaders($headers);
    }

    public function setContent(mixed $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function setStatusCode(int $statusCode): self
    {
        $this->statusCode = $statusCode;
        return $this;
    }

    /**
     * Replace all values for a header name.
     */
    public function setHeader(string $key, string $value): self
    {
        $this->headers[$key] = [$value];
        return $this;
    }

    /**
     * Append a value for a header name (preserves multiples, e.g. Set-Cookie).
     */
    public function addHeader(string $key, string $value): self
    {
        $existingKey = $this->findHeaderKey($key);
        if ($existingKey === null) {
            $this->headers[$key] = [$value];
            return $this;
        }

        $this->headers[$existingKey][] = $value;
        return $this;
    }

    public function withHeader(string $key, string $value): self
    {
        return $this->setHeader($key, $value);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getContent(): mixed
    {
        return $this->content;
    }

    /**
     * Body as raw bytes for transport layers.
     */
    public function getBodyBytes(): string
    {
        if (is_string($this->content)) {
            return $this->content;
        }

        if ($this->content === null) {
            return '';
        }

        if (is_scalar($this->content)) {
            return (string) $this->content;
        }

        return json_encode($this->content, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, list<string>>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * @return list<string>
     */
    public function getHeader(string $key): array
    {
        $existingKey = $this->findHeaderKey($key);
        if ($existingKey === null) {
            return [];
        }

        return $this->headers[$existingKey];
    }

    public static function json(mixed $data, int $statusCode = 200): self
    {
        return new self(
            json_encode($data),
            $statusCode,
            ['Content-Type' => 'application/json'],
        );
    }

    public static function html(string $content, int $statusCode = 200): self
    {
        return new self(
            $content,
            $statusCode,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    public function send(): void
    {
        http_response_code($this->statusCode);

        foreach ($this->headers as $key => $values) {
            $replace = true;
            foreach ($values as $value) {
                header("$key: $value", $replace);
                $replace = false;
            }
        }

        echo $this->getBodyBytes();
    }

    private function findHeaderKey(string $key): ?string
    {
        $target = strtolower($key);
        foreach ($this->headers as $name => $_) {
            if (strtolower($name) === $target) {
                return $name;
            }
        }

        return null;
    }
}
