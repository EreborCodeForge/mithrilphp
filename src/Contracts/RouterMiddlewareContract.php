<?php

declare(strict_types=1);

namespace Erebor\Mithril\Contracts;

use Erebor\Mithril\Http\HttpContext;
use Erebor\Mithril\Http\Response;

interface RouterMiddlewareContract
{
    public function handle(HttpContext $request, callable $next): Response;
}
