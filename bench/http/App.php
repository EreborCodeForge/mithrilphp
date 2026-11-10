<?php

declare(strict_types=1);

namespace Bench\Http;

use Erebor\Mithril\Container;
use Erebor\Mithril\Contracts\HttpApplication;
use Erebor\Mithril\Http\Request;
use Erebor\Mithril\Http\Response;

/**
 * Small HTTP application for the Eregion benchmark.
 * The database stand-in is an in-memory map. Optional fixed delay is local, not a network call.
 */
final class App implements HttpApplication
{
    private Container $container;

    private bool $booted = false;

    public function __construct()
    {
        $this->container = new Container();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $delay = max(0, (int) getenv('BENCH_DB_MICROS'));
        $this->container->singleton('catalog', new Catalog($delay));
        $this->container->scoped('request_log', static fn (): \stdClass => new \stdClass());
        $this->booted = true;
    }

    public function handle(Request $request): Response
    {
        return $this->middleware($request, function (Request $request): Response {
            $this->container->get('request_log');
            $row = $this->container->get('catalog')->find('item-1');

            return Response::json([
                'id' => $row['id'],
                'path' => $request->getPath(),
            ]);
        });
    }

    /**
     * @param callable(Request): Response $next
     */
    private function middleware(Request $request, callable $next): Response
    {
        return $next($request);
    }

    public function getContainer(): Container
    {
        return $this->container;
    }
}

final class Catalog
{
    public function __construct(private readonly int $delayMicros)
    {
    }

    /**
     * @return array{id: string}
     */
    public function find(string $id): array
    {
        if ($this->delayMicros > 0) {
            usleep($this->delayMicros);
        }

        return ['id' => $id];
    }
}
