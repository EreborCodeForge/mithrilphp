<?php

declare(strict_types=1);

namespace Erebor\Mithril\Core\Middleware;

use Erebor\Mithril\Core\Http\Request;
use Erebor\Mithril\Core\Http\Response;

interface MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response;
}
