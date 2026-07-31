<?php

declare(strict_types=1);

namespace Erebor\Mithril\Contracts;

use Erebor\Mithril\Container;
use Erebor\Mithril\Http\Request;
use Erebor\Mithril\Http\Response;

/**
 * Application contract for the warm Worker runtime.
 * Existing app Kernels typically already expose these methods.
 */
interface HttpApplication
{
    public function boot(): void;

    public function handle(Request $request): Response;

    public function getContainer(): Container;
}
