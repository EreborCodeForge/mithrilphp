<?php

declare(strict_types=1);

namespace Erebor\Mithril\Middleware;

use Erebor\Mithril\Http\Request;
use Erebor\Mithril\Http\Response;

interface MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response;
}
