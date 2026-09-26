# Job Worker Runtime

MithrilPHP can keep a **job** application warm across many units of work in the same process: boot once, then pay only for scoped work per job.

This path is parallel to the HTTP [`Worker`](runtime-worker.md). It does **not** use Eregion, UDS, or MessagePack.

## Flow

```text
app.boot() once
  └─ loop:
       job = transport.next()     // null → stop
       container.beginScope()
       result = app.handle(job)
       transport.ack|retry|reject(job, result)
       container.endScope()
       [optional] stop on SIGTERM between jobs
```

## Contracts

| Type | Role |
|------|------|
| `Erebor\Mithril\Contracts\JobApplication` | `boot()`, `handle(JobEnvelope): JobResult`, `getContainer()` |
| `Erebor\Mithril\Jobs\JobEnvelope` | Job id, name, payload, attempt, headers |
| `Erebor\Mithril\Jobs\JobResult` | Factories `ack()` / `retry()` / `reject()` |
| `Erebor\Mithril\Jobs\JobTransport` | `next()`, `ack()`, `retry()`, `reject()` |
| `Erebor\Mithril\Runtime\JobWorker` | Runs the loop |

Brokers (Redis, SQS, Rabbit, DB poll) live in app adapters. Mithril ships `InMemoryJobTransport` for tests and demos.

## Plug a JobKernel

```php
use Erebor\Mithril\Container;
use Erebor\Mithril\Contracts\JobApplication;
use Erebor\Mithril\Jobs\InMemoryJobTransport;
use Erebor\Mithril\Jobs\JobEnvelope;
use Erebor\Mithril\Jobs\JobResult;
use Erebor\Mithril\Jobs\JobTransport;

final class JobKernel implements JobApplication
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
        $this->booted = true;

        // Production: bind a real JobTransport adapter.
        $this->container->singleton(JobTransport::class, new InMemoryJobTransport([]));
    }

    public function handle(JobEnvelope $job): JobResult
    {
        // dispatch by $job->name …
        return JobResult::ack();
    }

    public function getContainer(): Container
    {
        return $this->container;
    }
}
```

## CLI

```bash
php vendor/bin/job-worker
php vendor/bin/job-worker --kernel=App\\JobKernel
```

After boot, the launcher requires `JobTransport` in the container. Missing binding → exit code `20` (`BootstrapFailure`).

### Kernel discovery (order)

1. `--kernel=Fqcn`
2. Env `MITHRIL_JOB_KERNEL`
3. `composer.json` → `extra.mithril.job_kernel`
4. Fallback `App\JobKernel`

Example app `composer.json`:

```json
{
  "extra": {
    "mithril": {
      "job_kernel": "App\\JobKernel"
    }
  }
}
```

## Exit codes

Reuses `WorkerExitCode`:

| Code | Meaning |
|------|---------|
| `0` | Normal stop (idle transport or SIGTERM) |
| `20` | Bootstrap failure (kernel / transport) |
| `22` | Scope cleanup failure |

## Non-goals (V1)

- Real broker implementations in core
- Unifying `HttpApplication` and `JobApplication`
- Autoscaling / metrics dashboard

Implementation spec: [worker-runtime.md](worker-runtime.md) (shipped in v2.2.0).
