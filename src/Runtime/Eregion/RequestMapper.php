<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Eregion;

use Erebor\Mithril\Http\Request;
use Erebor\Mithril\Runtime\Eregion\Messages\RequestEnvelope;

final class RequestMapper
{
    public function map(RequestEnvelope $envelope): Request
    {
        $uri = $envelope->uri;
        if ($uri === '' && $envelope->path !== '') {
            $uri = $envelope->path;
            if ($envelope->query !== '') {
                $uri .= '?' . $envelope->query;
            }
        }

        $query = [];
        if ($envelope->query !== '') {
            parse_str($envelope->query, $query);
        }

        $server = [
            'REQUEST_METHOD' => strtoupper($envelope->method),
            'REQUEST_URI' => $uri,
            'QUERY_STRING' => $envelope->query,
            'SERVER_PROTOCOL' => $envelope->protocol,
            'REMOTE_ADDR' => $envelope->remoteAddress,
            'HTTP_HOST' => $envelope->host,
            'REQUEST_SCHEME' => $envelope->scheme,
            'HTTPS' => $envelope->scheme === 'https' ? 'on' : 'off',
        ];

        if ($envelope->host !== '') {
            $server['SERVER_NAME'] = explode(':', $envelope->host)[0];
        }

        $contentType = null;
        foreach ($envelope->headers as $name => $values) {
            if (strtolower($name) === 'content-type' && $values !== []) {
                $contentType = $values[0];
                $server['CONTENT_TYPE'] = $contentType;
                break;
            }
        }

        $contentLength = strlen($envelope->body);
        if ($contentLength > 0) {
            $server['CONTENT_LENGTH'] = (string) $contentLength;
        }

        return Request::create(
            method: $envelope->method,
            uri: $uri,
            headers: $envelope->headers,
            rawBody: $envelope->body,
            server: $server,
            query: $query,
        );
    }
}
