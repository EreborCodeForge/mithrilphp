# SPEC-MITHRIL-001 — Job worker runtime (MithrilPHP)

**Status:** Shipped in `ereborcodeforge/mithrilphp` **v2.2.0**  
**Target package:** `ereborcodeforge/mithrilphp` ^2.2  
**Parent (Durin):** [SPEC-DX-017](../derived/017-worker-runtime-contract.md)  
**Related:** `HttpApplication`, `Runtime\Worker`, `bin/eregion-worker`, `JobApplication`, `Runtime\JobWorker`, `bin/job-worker`  
**Eregion impact:** none (see SPEC-DX-017 §9)

> Este documento é a **spec de implementação na lib Mithril**.  
> SPEC-DX-017 é o contrato de produto/fronteiras no Durin.  
> Guia de uso: [job-worker.md](job-worker.md).

---

## 1. Why Mithril must change

Hoje Mithril só tem o path HTTP warm:

```text
RequestBridge → Worker → HttpApplication::handle(Request): Response
entrypoint: bin/eregion-worker
```

Apps **job / queue / schedule** (preset Durin `worker`) precisam de um path paralelo **sem** Eregion, UDS ou MessagePack HTTP.

Durin **não** deve implementar o loop de processo (ADR-0002). Logo o gap é **obrigatoriamente** no Mithril.

## 2. Goal

Entregar na lib:

1. Contratos PHP (`JobApplication`, envelope, result).
2. Loop de processo (`JobWorker`) análogo a `Runtime\Worker`.
3. Entrypoint CLI `bin/job-worker` (+ discovery do kernel).
4. Transporte pluggable mínimo (`JobTransport`) com implementação in-memory para testes.
5. Testes unitários do ciclo boot → handle → ack/retry/reject → shutdown.

## 3. Non-goals

- Brokers reais (Redis, SQS, Rabbit, database poll concreto) — ficam em apps / adapters Durin.
- Mudanças no Eregion ou em `eregion-worker`.
- Unificar `HttpApplication` e `JobApplication`.
- Autoscaling, metrics server, dashboard.
- Recycle por max-jobs no V1 além do que já for trivial espelhar de `RecyclingPolicy` (opcional).

## 4. Decisions (settle SPEC-DX-017 open questions)

| # | Decision |
|---|----------|
| D1 | Interface canônica: **`JobApplication`** (não `WorkerApplication` — evita colisão com HTTP worker). |
| D2 | Namespace: `Erebor\Mithril\Contracts\JobApplication` + tipos em `Erebor\Mithril\Jobs\`. |
| D3 | Loop: `Erebor\Mithril\Runtime\JobWorker` (espelha `Worker`, não herda). |
| D4 | Entrypoint: `bin/job-worker` (Composer `bin`). |
| D5 | Discovery: `extra.mithril.job_kernel` → env `MITHRIL_JOB_KERNEL` → fallback `App\JobKernel`. |
| D6 | Sem dependência de Eregion no path job. |

## 5. Public API (normative)

### 5.1 `Erebor\Mithril\Contracts\JobApplication`

```php
namespace Erebor\Mithril\Contracts;

use Erebor\Mithril\Container;
use Erebor\Mithril\Jobs\JobEnvelope;
use Erebor\Mithril\Jobs\JobResult;

interface JobApplication
{
    public function boot(): void;

    public function handle(JobEnvelope $job): JobResult;

    public function getContainer(): Container;
}
```

Espelhar regras de `HttpApplication`: boot idempotente; sem side effects pesados no construtor.

### 5.2 `Erebor\Mithril\Jobs\JobEnvelope`

```php
final readonly class JobEnvelope
{
    /**
     * @param array<string, scalar|null> $headers
     */
    public function __construct(
        public string $id,
        public string $name,
        public mixed $payload,
        public int $attempt = 1,
        public array $headers = [],
    ) {}
}
```

### 5.3 `Erebor\Mithril\Jobs\JobResult`

Preferência: enum backed ou readonly com factory methods.

```php
enum JobOutcome: string
{
    case Ack = 'ack';
    case Retry = 'retry';
    case Reject = 'reject';
}

final readonly class JobResult
{
    public function __construct(
        public JobOutcome $outcome,
        public ?string $reason = null,
        public ?int $delayMs = null, // hint para retry
    ) {}

    public static function ack(): self { /* ... */ }
    public static function retry(?string $reason = null, ?int $delayMs = null): self { /* ... */ }
    public static function reject(?string $reason = null): self { /* ... */ }
}
```

### 5.4 `Erebor\Mithril\Jobs\JobTransport`

Fonte de jobs — **sem** I/O de broker no core além do contrato:

```php
interface JobTransport
{
    /** Próximo job ou null quando o transporte sinaliza idle/stop. */
    public function next(): ?JobEnvelope;

    public function ack(JobEnvelope $job): void;

    public function retry(JobEnvelope $job, JobResult $result): void;

    public function reject(JobEnvelope $job, JobResult $result): void;
}
```

V1 ship:

- `InMemoryJobTransport` (fila em memória) — testes + demo.
- Opcional depois: `CallableJobTransport` / null object.

Apps Durin implementam adapters reais em `App\Infrastructure`.

### 5.5 `Erebor\Mithril\Runtime\JobWorker`

```text
boot(app)
while running:
  job = transport.next()
  if job is null → stop (graceful)
  container.beginScope()
  try:
    result = app.handle(job)
    map result → transport.ack|retry|reject
  catch Throwable:
    transport.retry ou reject (política default: retry com reason)
    // processo NÃO precisa morrer
  finally:
    container.endScope()
  honor SIGTERM between jobs
```

Exit codes: reutilizar / espelhar `WorkerExitCode` onde fizer sentido (`BootstrapFailure`, success `0`).

**Não** usar `RequestBridge` nem pacotes `Runtime\Eregion\*`.

### 5.6 Resolution do application

Novo resolver (espelhar `ApplicationResolver` HTTP, sem paths Eregion):

```text
1. --kernel=Fqcn (CLI)
2. env MITHRIL_JOB_KERNEL
3. composer.json extra.mithril.job_kernel
4. fallback App\JobKernel
```

Validar `is_subclass_of(..., JobApplication::class)`.

### 5.7 CLI

`bin/job-worker`:

```bash
php vendor/bin/job-worker
php vendor/bin/job-worker --kernel=App\\JobKernel
# transporte: V1 pode fixar InMemory só para smoke; produção passa factory via
# future --transport=... ou binding no container após boot
```

V1 mínimo aceitável:

- Resolve kernel, boot, roda `JobWorker` com `InMemoryJobTransport` **ou** transporte obtido de `$app->getContainer()->get(JobTransport::class)` após boot (preferido se o container já estiver disponível pós-boot).

Ordem sugerida pós-boot:

```text
$app->boot();
$transport = $app->getContainer()->get(JobTransport::class); // app bind
(new JobWorker($app, $transport))->run();
```

Se binding ausente → erro claro (exit bootstrap), não silêncio.

Opcional DX Mithril: `forge job:work` thin wrapper → mesmo bin (não bloqueante para DoD).

## 6. Files to add (mithrilphp tree)

```text
src/Contracts/JobApplication.php
src/Jobs/JobEnvelope.php
src/Jobs/JobOutcome.php
src/Jobs/JobResult.php
src/Jobs/JobTransport.php
src/Jobs/InMemoryJobTransport.php
src/Runtime/JobWorker.php
src/Runtime/JobApplicationResolver.php   # ou sob Jobs/
bin/job-worker
tests/Unit/Jobs/*
tests/Unit/Runtime/JobWorkerTest.php
```

`composer.json`:

- registrar `"bin": [..., "bin/job-worker"]`
- documentar `extra.mithril.job_kernel` no README da lib

## 7. Compatibility

- Sem breaking change em `HttpApplication` / `Worker` / `eregion-worker`.
- Additive only.
- PHP version: seguir baseline atual da lib (alinhar com Durin `^8.5` quando a lib já exigir; senão manter o `require.php` vigente da lib e anotar).

## 8. Test plan (Mithril)

- [ ] `JobResult` factories / enum.
- [ ] `InMemoryJobTransport` FIFO + ack remove; retry requeue; reject drop.
- [ ] `JobWorker` boot failure → exit/bootstrap result.
- [ ] `handle` → ack chama `transport.ack`.
- [ ] `handle` → retry/reject mapeados.
- [ ] Exception em `handle` não aborta o loop (pelo menos N jobs seguintes).
- [ ] Resolver: extra / env / fallback (unit com fixtures).
- [ ] `bin/job-worker` smoke com kernel stub + in-memory binding.

## 9. Acceptance criteria

- [ ] Contratos públicos documentados no README Mithril.
- [ ] `JobWorker` + `bin/job-worker` verdes nos testes.
- [ ] Zero referência a Eregion no path job.
- [ ] SPEC-DX-017 pode marcar “Mithril runner: shipped” e Durin pode implementar preset `worker`.

## 10. Implementation phases (mithrilphp)

1. Types + `JobApplication` + unit tests.
2. `InMemoryJobTransport` + `JobWorker` loop.
3. Resolver + `bin/job-worker`.
4. README / changelog; tag minor (ex. 2.2.0).
5. Avisar Durin (SPEC-017 / preset).

## 11. Durin follow-up (out of this repo’s Mithril PR)

Após merge/tag no Mithril:

- Preset `worker` (`JobKernel`, `Jobs/`, bind `JobTransport` stub).
- `doctor` check: se `preset=worker` / `mode=job`, validar `JobApplication` em vez de só `HttpApplication`.
- Não exigir `eregion.yaml` / `server:check` para job-only.

## 12. Definition of Done

Lib Mithril publica o path job-worker testável; Durin consome via Composer bump — sem fork do loop no Durin.
