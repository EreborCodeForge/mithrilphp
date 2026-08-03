<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Eregion\Messages;

use Erebor\Mithril\Runtime\Eregion\Exceptions\UnexpectedMessageException;
use Erebor\Mithril\Runtime\Eregion\Protocol;

final readonly class RequestEnvelope
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public string $id,
        public string $method,
        public string $uri,
        public string $path,
        public string $query,
        public string $protocol,
        public array $headers,
        public string $body,
        public string $remoteAddress,
        public string $host,
        public string $scheme,
        public int $timeoutMs,
        public int $version = Protocol::VERSION,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $type = (string) ($data['type'] ?? '');
        if ($type !== Protocol::TYPE_REQUEST) {
            throw new UnexpectedMessageException(Protocol::TYPE_REQUEST, $type !== '' ? $type : '(missing)');
        }

        $id = (string) ($data['id'] ?? '');
        if ($id === '') {
            throw new UnexpectedMessageException('request with id', 'request without id');
        }

        $headers = [];
        foreach (($data['headers'] ?? []) as $name => $values) {
            if (is_array($values)) {
                $headers[(string) $name] = array_values(array_map(static fn (mixed $v): string => (string) $v, $values));
            } else {
                $headers[(string) $name] = [(string) $values];
            }
        }

        $body = $data['body'] ?? '';
        if (is_object($body) && method_exists($body, '__toString')) {
            $body = (string) $body;
        } elseif (!is_string($body)) {
            $body = is_scalar($body) || $body === null ? (string) $body : '';
        }

        return new self(
            id: $id,
            method: (string) ($data['method'] ?? 'GET'),
            uri: (string) ($data['uri'] ?? '/'),
            path: (string) ($data['path'] ?? '/'),
            query: (string) ($data['query'] ?? ''),
            protocol: (string) ($data['protocol'] ?? 'HTTP/1.1'),
            headers: $headers,
            body: $body,
            remoteAddress: (string) ($data['remote_address'] ?? ''),
            host: (string) ($data['host'] ?? ''),
            scheme: (string) ($data['scheme'] ?? 'http'),
            timeoutMs: (int) ($data['timeout_ms'] ?? 0),
            version: (int) ($data['version'] ?? Protocol::VERSION),
        );
    }
}
