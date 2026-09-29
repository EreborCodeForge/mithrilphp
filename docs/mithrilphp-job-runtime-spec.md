# MithrilPHP — Persistent Job Runtime Integration

**Status:** done (Phase 2 — released as v3.0.0)  
**Repository:** `EreborCodeForge/mithrilphp`

# Mission

Fortalecer o `JobWorker` já existente para operar como consumer persistente standalone ou supervisionado pelo Eregion.

MithrilPHP continua sendo responsável por:

```text
JobApplication
JobWorker
JobWorkerLauncher
JobTransport
JobEnvelope
JobResult
```

Eregion nunca é requisito para o worker funcionar.

# Current State

Fluxo atual:

```text
boot()
  ↓
transport->next()
  ↓
beginScope()
  ↓
handle(job)
  ↓
ack / retry / reject
  ↓
endScope()
  ↓
next job
```

O runtime de jobs já é separado do runtime HTTP e não usa Eregion/UDS/MessagePack.

# Target Architecture

```text
Eregion (opcional)
    ↓ supervisão
php vendor/bin/job-worker
    ↓
Mithril JobWorker
    ↓
JobTransport
    ↓
MQTT / SQS / Rabbit / Redis
```

# Lifecycle

Formalizar estados:

```text
starting
idle
busy
draining
stopped
failed
```

# Graceful Drain

SIGTERM/SIGINT:

```text
signal
  ↓
draining=true
  ↓
não buscar novo job
  ↓
terminar job atual
  ↓
endScope()
  ↓
exit 0
```

Se `next()` estiver bloqueado, o transport precisa ter forma de observar cancelamento.

Direção possível:

```php
interface InterruptibleJobTransport extends JobTransport
{
    public function stop(): void;
}
```

ou uma futura abstração de cancellation/context.

# Idle != Stop

Hoje `next(): ?JobEnvelope` usa `null` como término.

Para consumers reais isso é insuficiente: ausência temporária de mensagem não deve matar o processo.

Criar distinção explícita:

```php
final readonly class JobPollResult
{
    public static function job(JobEnvelope $job): self;
    public static function idle(): self;
    public static function stop(): self;
}
```

Se necessário para BC, criar contrato V2 e adapter para transports antigos.

# JobDispatcher

Adicionar abstração de fan-out/subjobs:

```php
interface JobDispatcher
{
    public function dispatch(
        string $name,
        array $payload,
        array $headers = [],
    ): void;
}
```

O handler publica novos jobs. Ele não cria processos.

```text
JobWorker
  ↓
JobDispatcher
  ↓
broker
  ↓
outros JobWorkers
```

# Recycling

Adicionar políticas para job worker:

```text
max_jobs
memory_limit
max_uptime (opcional)
scope_cleanup_failure
```

Não tratar recycle planejado como crash.

# Worker Result

Distinguir motivos:

```text
Stopped
Drained
Recycled
BootstrapFailure
ScopeCleanupFailure
TransportFailure
```

# Observer / Metrics Hooks

Criar observer opcional:

```php
interface JobWorkerObserver
{
    public function onBoot(): void;
    public function onIdle(): void;
    public function onJobStarted(JobEnvelope $job): void;
    public function onJobFinished(JobEnvelope $job, JobResult $result, float $duration): void;
    public function onRetry(JobEnvelope $job): void;
    public function onReject(JobEnvelope $job): void;
    public function onRecycle(string $reason): void;
}
```

Default: no-op.

Não acoplar core a Prometheus.

# Eregion Metadata

Aceitar metadados opcionais por env:

```text
EREGION_WORKLOAD
EREGION_WORKER_ID
EREGION_GENERATION
```

Criar DTO opcional para expor isso à aplicação/observabilidade.

Standalone continua funcionando sem essas envs.

# JobTransport

Core continua contendo apenas contrato.

MQTT/SQS/Rabbit/Redis devem viver em adapters/pacotes de infraestrutura.

O transport deve poder implementar:

```text
long polling
socket persistente
ACK
retry/requeue
reject/DLQ
shutdown interruptível
```

# Must Do

- manter `job-worker` standalone;
- graceful drain;
- idle distinto de stop;
- recycle por jobs/memória;
- `JobDispatcher`;
- observer hooks;
- preservar scope por job;
- metadata opcional do supervisor.

# Must Not

- exigir Eregion;
- colocar lógica MQTT/SQS dentro de `JobWorker`;
- spawnar workers a partir de handlers;
- fundir `HttpApplication` e `JobApplication` só por conveniência;
- criar protocolo Eregion/job nesta fase.

# Tests

```text
boot uma vez
múltiplos jobs
idle sem exit
stop
SIGTERM idle
SIGTERM durante job
ack/retry/reject
max_jobs recycle
memory recycle
scope cleanup
observer
dispatcher
transport failure
```

# Acceptance Criteria

- Worker pode permanecer vivo indefinidamente durante períodos sem mensagens.
- SIGTERM finaliza job corrente e encerra.
- Um processo executa muitos jobs.
- Subjobs usam dispatcher.
- Eregion continua opcional.

# Definition of Done

MithrilPHP é a camada confiável de **execução persistente de jobs**, apta a ser multiplicada e supervisionada por Eregion.
