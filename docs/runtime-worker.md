# Warm Worker Runtime

MithrilPHP can keep an application **warm** across many HTTP requests in the same process: boot and compile once, then pay only for scoped work per request.

This is the same model RoadRunner / FrankenPHP use at the transport layer. Mithril owns the **loop + container lifetimes**; you plug any transport via `RequestBridge`.

## Flow

```text
app.boot() once  (prefer loadCompiled + compiled routes)
  └─ loop:
       request  = bridge.next()        // null → stop
       container.beginScope()
       response = app.handle(request)
       bridge.respond(response)
       container.endScope()
       [optional] stop after maxRequests
```

## Contracts

| Type | Role |
|------|------|
| `Erebor\Mithril\Contracts\HttpApplication` | `boot()`, `handle(Request): Response`, `getContainer()` |
| `Erebor\Mithril\Runtime\RequestBridge` | `next(): ?Request`, `respond(Response): void` |
| `Erebor\Mithril\Runtime\Worker` | Runs the loop |

Existing app Kernels usually already expose those three methods — add `implements HttpApplication` and you are plug-compatible.

## FPM / built-in server (`index.php`)

Same Worker path as long-running mode; the bridge serves **one** request then returns `null`:

```php
<?php

declare(strict_types=1);

use App\Core\Kernel;
use Erebor\Mithril\Runtime\FpmOnceBridge;
use Erebor\Mithril\Runtime\Worker;

require __DIR__ . '/../vendor/autoload.php';

$kernel = new Kernel();
(new Worker($kernel, new FpmOnceBridge()))->run();
```

## Long-running entrypoint

Use any bridge that keeps calling `next()` until shutdown. For local proof without external servers, `InMemoryBridge` queues requests:

```php
<?php

declare(strict_types=1);

use App\Core\Kernel;
use Erebor\Mithril\Http\Request;
use Erebor\Mithril\Runtime\InMemoryBridge;
use Erebor\Mithril\Runtime\Worker;

require __DIR__ . '/../vendor/autoload.php';

$kernel = new Kernel();

$bridge = new InMemoryBridge([
    new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'], [], [], []),
    // …more requests
]);

// Recycle the process after N requests (supervisor restarts a fresh warm worker)
(new Worker($kernel, $bridge, maxRequests: 1000))->run();
```

Custom transports (RoadRunner, FrankenPHP, TCP, etc.) only need a `RequestBridge` implementation. Those adapters are intentionally out of the core package.

## Compiled container + warm baseline

```php
$container->loadCompiled(
    factories: $data['factories'],
    singletons: $data['singletons'],
    preloaded: $data['preloaded'], // Router, config, shared services
    strict: true
);
```

- **preloaded** become the warm baseline kept across `resetWorker()`
- **compiled singletons** resolve once, then live in `instances` until reset
- **scoped** services are created inside `beginScope` and destroyed in `endScope` (Worker does this every request)

```php
$container->resetWorker(); // clears scoped + lazy instances; restores preloaded baseline
```

Prefer `endScope()` per request. Call `resetWorker()` only when recycling a long-lived worker without exiting the process (memory pressure). With `maxRequests`, exiting the process is usually cleaner; the supervisor starts a new worker that loads the compiled cache again (still cheap).

## Lifetimes checklist (migration)

1. **Do not** register `Request` / `HttpContext` as singletons — use `scoped()` or create them in `handle`.
2. Middleware that holds request state must be **scoped** or **stateless**.
3. Put Router, HandlerResolver, config, logger in **singleton / preloaded** (compiled).
4. Keep `boot()` idempotent (`if ($this->booted) return;`) — Worker may call it once; FPM also boots once per process.
5. Prefer compiled container + compiled routes before enabling a multi-request bridge.

## Efficiency notes

- Hot path: no reflection when `loadCompiled(..., strict: true)` and every service is in the artifact.
- Worker does not wrap `handle()` in a generic catch — your Kernel keeps exception handling.
- Scope open/close is the only container bookkeeping per request when bindings are already warm.

## Related APIs

- `Container::scoped()`, `beginScope()`, `endScope()`, `resetWorker()`
- `Container::loadCompiled()`, `isCompiled()`
- `Router::loadCompiledRoutes()` for O(1) matching without rebuilding routes each boot
