<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime;

use Erebor\Mithril\Contracts\HttpApplication;

/**
 * Long-running HTTP loop: boot once, then scope → handle → respond per request.
 * Pair with a compiled container for maximum efficiency.
 */
final class Worker
{
    public function __construct(
        private readonly HttpApplication $app,
        private readonly RequestBridge $bridge,
        private readonly int $maxRequests = 0,
    ) {}

    /**
     * @return int Number of requests served
     */
    public function run(): int
    {
        $this->app->boot();

        $container = $this->app->getContainer();
        $served = 0;

        while ($request = $this->bridge->next()) {
            $container->beginScope();
            try {
                $this->bridge->respond($this->app->handle($request));
            } finally {
                $container->endScope();
            }

            $served++;

            if ($this->maxRequests > 0 && $served >= $this->maxRequests) {
                break;
            }
        }

        return $served;
    }
}
