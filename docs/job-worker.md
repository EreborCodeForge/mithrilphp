# Job Worker Runtime

MithrilPHP can keep a **job** application warm across many units of work in the same process: boot once, then pay only for scoped work per job.

This path is parallel to the HTTP [`Worker`](runtime-worker.md). It does **not** use Eregion, UDS, or MessagePack. Eregion may optionally supervise the process; it is never required.

## Flow

```text
app.boot() once
  └─ loop:
       poll = transport.poll()
         idle  → stay alive (onIdle)
         stop  → exit Stopped
         job   → beginScope → handle → ack|retry|reject → endScope
       recycle policies (max_jobs / memory / max_uptime)
       SIGTERM/SIGINT → drain (finish current job, no new poll) → Drained
```

## Contracts

| Type | Role |
|------|------|
| `Erebor\Mithril\Contracts\JobApplication` | `boot()`, `handle(JobEnvelope): JobResult`, `getContainer()` |
| `Erebor\Mithril\Jobs\JobEnvelope` | Job id, name, payload, attempt, headers |
| `Erebor\Mithril\Jobs\JobResult` | Factories `ack()` / `retry()` / `reject()` |
| `Erebor\Mithril\Jobs\JobPollResult` | `job()` / `idle()` / `stop()` |
| `Erebor\Mithril\Jobs\JobTransport` | `poll()`, `ack()`, `retry()`, `reject()` |
| `Erebor\Mithril\Jobs\InterruptibleJobTransport` | `stop()` to unblock a blocking poll during drain |
| `Erebor\Mithril\Jobs\JobDispatcher` | Fan-out: publish subjobs to the broker (no process spawn) |
| `Erebor\Mithril\Runtime\JobWorker` | Persistent loop |
| `Erebor\Mithril\Runtime\JobWorkerObserver` | Optional observability hooks (default no-op) |

Brokers (Redis, SQS, Rabbit, DB poll) live in app adapters. Mithril ships `InMemoryJobTransport` and `InMemoryJobDispatcher` for tests and demos.

### Idle vs stop

Temporary absence of messages must return `JobPollResult::idle()`, not stop. Only an explicit stop (or interrupt after drain) ends the process. `InMemoryJobTransport` defaults to stop-when-empty for finite tests; pass `idleWhenEmpty: true` for a persistent consumer.

Legacy transports that still expose `next(): ?JobEnvelope` can be wrapped with `LegacyJobTransportAdapter` (`null` → `stop()`).

## Plug a JobKernel

```php
use Erebor\Mithril\Container;
use Erebor\Mithril\Contracts\JobApplication;
use Erebor\Mithril\Jobs\InMemoryJobDispatcher;
use Erebor\Mithril\Jobs\InMemoryJobTransport;
use Erebor\Mithril\Jobs\JobDispatcher;
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

        $transport = new InMemoryJobTransport([], idleWhenEmpty: true);
        $this->container->singleton(JobTransport::class, $transport);
        $this->container->singleton(JobDispatcher::class, new InMemoryJobDispatcher($transport));
    }

    public function handle(JobEnvelope $job): JobResult
    {
        // dispatch by $job->name; publish subjobs via JobDispatcher
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

Optional container bindings:

- `RecyclingPolicy` — custom recycle rules
- `JobWorkerObserver` — metrics / logging hooks

### Kernel discovery (order)

1. `--kernel=Fqcn`
2. Env `MITHRIL_JOB_KERNEL`
3. `composer.json` → `extra.mithril.job_kernel`
4. Fallback `App\JobKernel`

### Optional Eregion supervisor metadata

When supervised, these envs are accepted and exposed via `EregionWorkloadMetadata` (never required):

```text
EREGION_WORKLOAD
EREGION_WORKER_ID
EREGION_GENERATION
```

## Exit codes

Reuses `WorkerExitCode`:

| Code | Meaning |
|------|---------|
| `0` | Normal stop, drain (SIGTERM), or remote shutdown |
| `10` | Planned recycle (`max_jobs` / memory / `max_uptime`) |
| `20` | Bootstrap failure (kernel / transport) |
| `21` | Transport failure |
| `22` | Scope cleanup failure |

## Non-goals

- Real broker implementations in core
- Unifying `HttpApplication` and `JobApplication`
- Spawning workers from handlers
- Eregion/job protocol in this phase

Integration spec: [job-runtime-spec.md](job-runtime-spec.md).
