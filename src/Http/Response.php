<?php

declare(strict_types=1);

namespace Erebor\Mithril\Http;

class Response
{
    public function __construct(
        private mixed $content = '',
        private int $statusCode = 200,
        private array $headers = []
    ) {}

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

    public function setHeader(string $key, string $value): self
    {
        $this->headers[$key] = $value;
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

    public static function json(mixed $data, int $statusCode = 200): self
    {
        $instance = new self();
        $instance->content = json_encode($data);
        $instance->statusCode = $statusCode;
        $instance->headers['Content-Type'] = 'application/json';
        return $instance;
    }

    public static function html(string $content, int $statusCode = 200): self
    {
        $instance = new self();
        $instance->content = $content;
        $instance->statusCode = $statusCode;
        $instance->headers['Content-Type'] = 'text/html; charset=UTF-8';
        return $instance;
    }

    public static function noContent(): self
    {
        $instance = new self();
        $instance->content = '';
        $instance->statusCode = 204;
        return $instance;
    }

    public function send(): void
    {
        http_response_code($this->statusCode);

        foreach ($this->headers as $key => $value) {
            header("$key: $value");
        }

        echo $this->content;
    }
}
