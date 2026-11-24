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

    /** @var list<string> */
    private array $resident = [];

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
        $hold = max(0, (int) getenv('BENCH_HOLD_MB'));
        for ($i = 0; $i < $hold; $i++) {
            $this->resident[] = random_bytes(1048576);
        }
        $this->booted = true;
    }

    public function handle(Request $request): Response
    {
        return $this->middleware($request, function (Request $request): Response {
            $this->container->get('request_log');
            $row = $this->container->get('catalog')->find('item-1');
            $cpu = max(0, (int) getenv('BENCH_CPU_ITERS'));
            $buf = 'bench';
            for ($i = 0; $i < $cpu; $i++) {
                $buf = hash('sha256', $buf, true);
            }
            $upstream = getenv('BENCH_UPSTREAM_URL');
            if (is_string($upstream) && $upstream !== '') {
                $parts = parse_url($upstream);
                $host = is_array($parts) ? ($parts['host'] ?? '') : '';
                $port = is_array($parts) ? (int) ($parts['port'] ?? 0) : 0;
                if ($host !== '' && $port > 0) {
                    $fp = @stream_socket_client('tcp://'.$host.':'.$port, $errno, $errstr, 2);
                    if (is_resource($fp)) {
                        fwrite($fp, "GET / HTTP/1.0\r\nHost: {$host}\r\nConnection: close\r\n\r\n");
                        stream_get_contents($fp);
                        fclose($fp);
                    }
                }
            }

            return Response::json([
                'id' => $row['id'],
                'path' => $request->getPath(),
                'cpu' => $cpu > 0 ? strlen($buf) : 0,
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
