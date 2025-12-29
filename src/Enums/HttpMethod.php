<?php

declare(strict_types=1);

namespace Erebor\Mithril\Core\Enums;

enum HttpMethod: string
{
    case GET = 'GET';
    case POST = 'POST';
    case PUT = 'PUT';
    case DELETE = 'DELETE';
    case OPTIONS = 'OPTIONS';
    case PATCH = 'PATCH';
    case HEAD = 'HEAD';
}
