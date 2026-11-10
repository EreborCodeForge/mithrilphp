# SPEC-WG-001 — WorkerGuard: auditoria e mitigação de estado residual em workers PHP persistentes

**Status:** Proposed · **Versão da spec:** 1.0.0 · **Data-base:** 2026-11-10  
**Escopo:** MithrilPHP + Eregion + Durin’s Forge  
**Tipo:** Master technical specification / implementação incremental  
**Regra de interpretação:** este documento define o comportamento-alvo, **não** afirma que WorkerGuard já está implementado.

> **Objetivo:** transformar o ciclo persistente de workers PHP em uma superfície **observável e auditável**, capaz de identificar indícios verificáveis de estado residual, classificar riscos e recomendar ou solicitar reciclagem segura ao supervisor existente — sem prometer isolamento completo de PHP arbitrário.

---

## 0. Instrução ao agente implementador

1. Tratar esta SPEC como **referência funcional e de limites arquiteturais**. Antes de editar, ler o código atual e os testes dos repositórios; preservar os contratos existentes quando a implementação tiver evoluído.
2. **Não** reimplementar supervisão, processo ou protocolo em Durin/Mithril. **Não** adicionar `#[Workload]` nem criar outro conceito de workload: o Eregion já suporta workloads HTTP e consumer.
3. Desenvolver **primeiro o MVP HTTP** no MithrilPHP, depois CLI no Durin e por último incrementos mínimos no Eregion, apenas se comprovadamente necessários.
4. Realizar mudanças idempotentes, pequenas, com testes unitários e integração por repositório. Não alterar compatibilidade do EREGION/1 no MVP.
5. Não alegar "isolamento garantido", "zero leak" ou detecção universal. Relatar detecções, cobertura dos probes e limitações explicitamente.
6. **Não** alterar o significado de `beginScope`/`endScope`, a semântica de resposta HTTP, o ACK/retry/reject de jobs nem as políticas existentes de reciclagem.
7. Em caso de tensão entre esta spec e o código atual, registrar a divergência e propor a menor alteração compatível; não inventar endpoints e hooks de versões inexistentes.
8. Não submeter alterações em outros repositórios silenciosamente; cada etapa tem gates independentes de aprovação/merge.

## 1. Baseline confirmado e pontos de extensão

### 1.1 Implementado no código examinado

| Repositório | Evidência | Aproveitamento |
|---|---|---|
| MithrilPHP | `src/Runtime/Worker.php`: `boot()` uma vez; ciclo `beginScope → handle → endScope`; consulta `RecyclingPolicy`; monta `WorkerMetadata` | Injetar audit hooks **sem trocar** o loop existente |
| MithrilPHP | `src/Container.php`: `scoped`, `beginScope`, `endScope`, `resetWorker`, instâncias singleton e compiladas | Instrumentação segura e read-only sobre ciclo de vida de escopos |
| MithrilPHP | `src/Runtime/Recycling/*` | Estender política por composição, sem duplicar decisões de memória/max-requests |
| MithrilPHP | `src/Runtime/WorkerMetadata.php`, `Eregion/ResponseMapper.php` e `EregionBridge.php` | Já propagam `recycle` e `recycle_reason` na resposta |
| Eregion | `internal/protocol/messages.go`: `ResponseMeta{Recycle, RecycleReason}` | **Sem protocolo novo** no MVP |
| Eregion | `internal/worker/pool.go`: `Release` respeita sinal de reciclagem após resposta | Reciclagem planejada existente, com métricas e replacement |
| Eregion | `internal/workload/*`: workloads `http` e `consumer` com pools por workload | Não criar framework paralelo de workload |
| Durin’s Forge | `src/Console/Application.php`: registro de comandos | Adicionar `worker:audit` |
| Durin’s Forge | `src/Console/Commands/DoctorCommand.php`: estilo CLI e formato JSON | Reutilizar padrões de argumentos/saída |

### 1.2 Ainda **não confirmado como implementado**

- Um `WorkerGuard` com probes, findings estruturados, classificação de severidade e auditoria determinística entre duas ou mais requisições.
- `durin worker:audit` e `durin worker:inspect`.
- Relatório nativo com cobertura de probes e políticas de estado residual.
- Detecção universal de variáveis estáticas, objetos retidos ou recursos de extensões PHP: **não é realizável de maneira genérica e segura no runtime PHP usual**.

**Links para código-fonte:**
- https://github.com/EreborCodeForge/mithrilphp/blob/main/src/Runtime/Worker.php
- https://github.com/EreborCodeForge/mithrilphp/blob/main/src/Container.php
- https://github.com/EreborCodeForge/mithrilphp/blob/main/src/Runtime/Eregion/EregionBridge.php
- https://github.com/EreborCodeForge/eregion/blob/main/internal/protocol/messages.go
- https://github.com/EreborCodeForge/eregion/blob/main/internal/worker/pool.go
- https://github.com/EreborCodeForge/durins-forge/blob/main/src/Console/Application.php

> Nota operacional: o CI do Eregion tinha falha no teste `TestConsumerScaleDownForceKill` na execução de 2026-09-29. Tratar estabilização do CI como pré-requisito para o gate de integração; **não** atribuir automaticamente esse erro ao WorkerGuard.

---

## 2. Problema e hipótese de valor

Workers PHP persistentes evitam custos repetidos de bootstrap, mas podem reter: contexto de autenticação, estado estático, caches em escopo errado, referências a objetos de requisições anteriores, cursores/streams e outras estruturas. `endScope()` limpa instâncias registradas como `scoped`, mas **não** consegue provar que qualquer estado arbitrário do processo foi limpo. Memória alocada por PHP também pode continuar reservada pelo alocador sem ser um vazamento real.

**Hipótese:** um runtime que faça inspeções explícitas, reproduzíveis e com baixo custo — separadas entre `audit` e `guard` — reduz incidentes e o esforço de diagnóstico, sem sacrificar o modelo de execução persistente.

### 2.1 Resultados esperados

- Identificar mudanças indevidas em **watchpoints explicitamente registrados** como request-scoped.
- Demonstrar, com testes A → B no **mesmo processo**, que um segredo/contexto fictício não atravessa requisições.
- Distinguir `observation` de `suspected_leak` e de `confirmed_policy_violation`.
- Solicitar reciclagem de **worker HTTP** via metadata já existente quando uma política local determinar risco alto.
- Expor findings seguros para logs, JSON e CLI, com classificação, origem, evidência **não sensível** e recomendação.
- Preservar o caminho sem instrumentação: `off` é default e não modifica o comportamento atual.

### 2.2 Não objetivos do MVP

- Snapshot completo do heap, restauração automática de static properties arbitrárias, sandbox por usuário ou garantia de isolamento de extensões nativas.
- Reiniciar os processos com um novo mecanismo; o Eregion já faz isso.
- Alterar o protocolo HTTP entre Go/PHP, alterar `EREGION/1` ou adicionar streams de telemetria por socket.
- Interceptar automaticamente valores de Authorization, cookies, payloads ou conteúdo de sessões.
- Implementar job workers, consumidores ou revisão de semântica `ack/retry/reject` na primeira entrega.
- Escalonamento SLO, Autopilot, workloads via attributes ou deploy geracional nesta iniciativa.

---

## 3. Arquitetura e ownership

```text
Request HTTP
    |
    v
Eregion (Go): dispatcher -> pool HTTP -> Worker PHP
                                      |
                                      v
MithrilPHP Worker
    boot app uma vez
    beginScope()
    WorkerGuard.beforeRequest() [quando habilitado; baseline autorizado]
    app.handle(request)
    endScope()              [sempre, com proteção de finally]
    WorkerGuard.afterRequest()  -> findings -> GuardDecision
    Existing RecyclingPolicy + GuardDecision (precedência explícita)
    WorkerMetadata(recycle=true, recycle_reason="guard_state_violation")
    EregionBridge.respond(response, metadata)
                                      |
                                      v
Eregion Pool.Release(): finaliza uso -> drain/recycle planejado -> nova geração
                                      |
                                      v
Durin CLI: auditoria offline e renderização de relatórios
```

**Ownership obrigatório**

| Responsabilidade | Dono |
|---|---|
| Probe lifecycle, baseline, findings, classificação, redaction, política | **MithrilPHP** |
| Integração com `Worker.php`, escopos e `RecyclingPolicy` | **MithrilPHP** |
| Supervisão, descarte, restart, métricas do pool, limites operacionais | **Eregion** |
| CLI, configuração da aplicação, cenários de teste e relatórios de DX | **Durin’s Forge** |
| Regras de arquitetura estática ou drift entre módulos | **Durin Architecture**, somente evolução futura e opcional |

**Invariante:** Eregion nunca conhece classes PHP, nomes de propriedades nem dados da aplicação. Recebe apenas motivos de reciclagem em vocabulário finito e métricas agregadas. Durin nunca gerencia PIDs.

---

## 4. Modos, severidade e ações

| Modo | Onde | Custo | Comportamento |
|---|---|---|---|
| `off` **(default)** | todos | próximo de zero | zero probes, zero mudanças no ciclo de vida |
| `observe` | dev/staging ou produção com opt-in | baixo/limitado | coleta bounded, logs/contadores; **não** recicla por findings do Guard |
| `enforce` | produção opt-in | baixo/limitado | executa probes permitidos; somente violações de **alta confiança** podem pedir reciclagem após resposta |
| `audit` | teste/CLI | mais alto | cenários A/B, static watchpoints autorizados, assertions e saída JSON/text; falha no CI conforme resultado |

**Severidades:** `info`, `warning`, `critical`.  
**Confiança:** `observed`, `suspected`, `verified`.  
**Ações:** `none`, `log`, `recycle_after_response`, `fail_audit`.

Regras:
1. `warning/suspected` nunca recicla por padrão; especialmente métricas de crescimento de memória.
2. `critical/verified` pode reciclar em `enforce` **apenas** quando a política foi ativada explicitamente e o probe for seguro.
3. `scope_cleanup_failure` mantém tratamento **já existente**, com prioridade maior que as razões do Guard.
4. O ciclo de request não deve falhar só porque a instrumentação falhou em produção: falha de probe vira `guard_probe_error` com contador, sem exposição de dados; o modo `audit --strict` pode falhar.
5. O Guard deve ser determinístico, bounded, reentrância proibida, sem callbacks que abram conexões ou executem I/O de negócio.

### 4.1 Precedência de decisões de reciclagem

`scope_cleanup_failure` > políticas críticas já existentes de proteção > `guard_state_violation` > `guard_resource_violation` > `max_requests` e `memory_limit` conforme política atual.  
**Nota:** preservar o comportamento da política existente mesmo quando múltiplas razões concorram; se houver uma razão já decidida, registrar as demais em log/relatório em vez de sobrescrevê-la. Esta ordem é um **objetivo de classificação**, não licença para mudar compatibilidade de API sem testes.

---

## 5. Modelo de domínio e contratos propostos (MithrilPHP)

**Namespace sugerido**: `Erebor\Mithril\Runtime\Guard\` (seguir estilo do repositório).

```php
namespace Erebor\Mithril\Runtime\Guard;

enum GuardMode: string { case Off = 'off'; case Observe = 'observe'; case Enforce = 'enforce'; case Audit = 'audit'; }
enum FindingSeverity: string { case Info = 'info'; case Warning = 'warning'; case Critical = 'critical'; }
enum FindingConfidence: string { case Observed = 'observed'; case Suspected = 'suspected'; case Verified = 'verified'; }

final readonly class GuardFinding
{
    public function __construct(
        public string $code,            // allowlist: e.g. guard_state_retained
        public string $probeId,         // identificador estável e seguro
        public FindingSeverity $severity,
        public FindingConfidence $confidence,
        public string $safeSummary,    // sem valores confidenciais
        public string $recommendation,
        public string $scope,          // http; futuramente job
    ) {}
}

final readonly class GuardDecision
{
    /** @param list<GuardFinding> $findings */
    public function __construct(
        public array $findings,
        public bool $recycle,
        public ?string $recycleReason = null,
    ) {}
}

interface GuardProbe
{
    public function id(): string;
    public function before(GuardCycleContext $context): void;
    /** @return list<GuardFinding> */
    public function after(GuardCycleContext $context): array;
}

interface WorkerGuard
{
    public function before(GuardCycleContext $context): void;
    public function after(GuardCycleContext $context): GuardDecision;
}
```

Contratos ilustram a superfície desejada, não classes existentes. Implementação final pode reduzir o número de DTOs, desde que preserve semântica, testes e serialização.

### 5.1 `GuardCycleContext`

Somente metadados do ciclo, tais como `workerGeneration`, `requestOrdinal`, `mode`, identificador opaco de correlação, timestamps monotônicos e estatísticas numéricas. **Não** guardar o objeto Request, body, token, cookies, sessão ou identidade de usuário dentro de relatórios/probes genéricos. Contexto de request pode ser entregue via uma interface read-only somente quando um probe explicitamente requerer esse acesso — nunca serializar.

### 5.2 `GuardProbe` — regras

- Execução **síncrona** e com orçamento configurável; sem threads, hooks escondidos ou serviços adicionais obrigatórios.
- `before` e `after` no mesmo ciclo; `after` ocorre **após** `endScope()` e **antes** de enviar resposta.
- IDs, códigos de findings, nomes de regras e labels das métricas devem vir de allowlists ou ter cardinalidade estritamente limitada.
- Implementações internas se encarregam de sanitizar qualquer amostra; não reter valores de negócio em memória do Guard após fechamento do ciclo.
- Failure isolation: exceção de um probe não impede execução da app; produzir finding operacional sanitizado e continuar os demais probes no modo `observe`; em `audit`, erro invalida o teste.
- Sanitizar também mensagens de exceção: não serializar `Throwable::getMessage()` indiscriminadamente.

### 5.3 Probes MVP

**A. ScopedContainerProbe** — inspecionar o ciclo das instâncias com lifetime `scoped` sem acessar objetos de negócio. Introduzir, se necessário, método interno read-only com **contagem**/estado da registry (ou evento interno), nunca expor a coleção pública de instâncias. Validar que `endScope` foi executado e o registro interno foi esvaziado. A existência de referências externas remanescentes **não** é detectada por simples contagem.

**B. ExplicitStaticStateProbe** — somente classes/propriedades **registradas explicitamente** pela aplicação (ex.: `App\Security\UserContext::$userId`), com semântica de lifetime `request`. Capturar baseline seguro antes do cenário A, verificar retorno ao baseline após A e antes de B. Inspecionar apenas propriedades elegíveis, sem executar getters de usuário, serializar objetos, atravessar grafos, incluir valores no relatório ou permitir alteração de visibilidade como efeito colateral persistente. Comparação por igualdade tipada para scalars permitidos, ou resumos opacos efêmeros criados por função autorizada. **Nunca** registrar valor de propriedade, mesmo em debug.

**C. MemoryTrendProbe** — observar `memory_get_usage(false)`, `memory_get_usage(true)` e `memory_get_peak_usage(true)` como sinais distintos. Janela fixa e limites de amostras; amostragem por frequência; delta sustentado após warm-up como `suspected` e **não** prova de leak. Primeira versão **não** recicla automaticamente por tendência (política de memória existente permanece responsável por limite duro).

**D. ResourceProbe (opt-in / fase posterior do MVP)** — instrumentar somente recursos gerenciados pelos adapters/framework (ex.: contadores de conexões, streams registrados, transações abertas). Comparar `outstanding` com baseline; ignorar pools reutilizáveis declarados. Não afirmar que enumera todos os recursos globais/extensões do processo.

### 5.4 Probes não permitidos no MVP

- Refletir automaticamente todas as classes carregadas, ler todos os estáticos ou serializar todas as instâncias.
- Gravar `$_SESSION`, `$_COOKIE`, headers, tokens ou conteúdo de payload em logs.
- Resetar estado estático de aplicações sem contrato explícito da classe.
- Destruir recursos de negócio automaticamente sob suspeita, sem passar pelo cleanup existente.

---

## 6. Integração exata no `MithrilPHP/src/Runtime/Worker.php`

1. Adicionar parâmetro **opcional e retrocompatível** para resolução de `WorkerGuard` (ou `NullWorkerGuard`) à construção/configuração, ao final dos argumentos atuais. `off` deve ser no-op.
2. Inicializar/configurar probes **após** `app->boot()`; não executar auditoria quando o bootstrap falhar.
3. Para cada `bridge->next() != null`, preparar contexto. Coletar baseline de probes com ciclo válido; executar hooks definidos sem modificar a lógica de negócio.
4. Preservar literalmente a garantia de chamar `endScope()` no `finally` após `handle()`. Se `beginScope` falhar, tratar via caminho de falha já existente ou adicionar teste explícito (não tentar `endScope` sobre escopo não iniciado).
5. Executar guard `after()` após fechamento do escopo; combinar `GuardDecision` com `RecyclingPolicy`, aplicando as regras de precedência.
6. Construir `WorkerMetadata` usando campos existentes (`recycle`, `recycleReason`) e **responder uma única vez**. Nunca incluir findings ou dados sensíveis no corpo HTTP por causa do Guard.
7. Se o Guard pedir reciclagem, parar o loop **após** o envio da resposta e retornar `WorkerStopReason::Recycled`, mantendo o caminho atual.
8. Se o transporte de resposta falhar, prevalece `ProtocolFailure`; não duplicar resposta ou considerar worker saudável.
9. Se `endScope` falhar, preservar `ScopeCleanupFailure`, não ocultar a falha com mensagem genérica.
10. Garantir que o observer Guard não permaneça segurando objeto Request, Response ou referência de contexto entre ciclos.

Pseudocódigo normativo (não copiar literalmente para produção):

```php
$scopeStarted = false;
$scopeFailed = false;
try {
    $container->beginScope();
    $scopeStarted = true;
    safeGuardBefore($guard, $cycle); // adapter absorve e registra falhas do probe
    try {
        $response = $app->handle($request);
    } catch (Throwable) {
        $response = Response::json(['error' => 'Internal Server Error'], 500);
    }
} finally {
    if ($scopeStarted) {
        try {
            $container->endScope();
        } catch (Throwable) {
            $scopeFailed = true;
        }
    }
}
$guardDecision = safeGuardAfter($guard, $cycle); // estado após cleanup
$recycle = combine($existingRecyclingPolicy, $guardDecision, $scopeFailed);
$bridge->respond($response, new WorkerMetadata(
    requestsHandled: $served,
    memoryUsage: memory_get_usage(true),
    memoryPeak: memory_get_peak_usage(true),
    recycle: $recycle->shouldRecycle,
    recycleReason: $recycle->reason,
));
// Only now exit/recycle the worker.
```

**Atenção:** `safeGuardBefore` e `safeGuardAfter` são nomes ilustrativos para uma camada que contém exceções de probes, não APIs existentes. O código real deve tratar bootstrap/beginScope e erros de bridge segundo os contratos atuais, nunca enviar resposta duplicada e priorizar `ScopeCleanupFailure` quando aplicável.

---

## 7. Integração no Eregion: mínima e sem alteração de protocolo

### 7.1 MVP obrigatório: reaproveitar o contrato existente

- `ResponseMeta.Recycle` e `ResponseMeta.RecycleReason` **já existem** em `internal/protocol/messages.go`.
- `Pool.Release` **já** marca reciclagem planejada, fecha conexão/retira de circulação e contabiliza `IncRecycle(reason)`.
- `Dispatcher.ServeHTTP` **já** chama `Pool.Release(worker, response)` antes de escrever headers/body HTTP; para o consumidor, a resposta normalmente será escrita depois sem reutilizar o worker.
- Introduzir apenas um conjunto fechado de razões: `guard_state_violation`, `guard_resource_violation`; `guard_probe_error` é apenas diagnóstico e **não** causa reciclagem no padrão.
- Evitar passar nomes dinâmicos de classes, propriedades, paths, mensagens de erro ou IDs de requisição como `recycle_reason`. Isso causaria alta cardinalidade de métricas e vazamento de dados.
- Validar limite de comprimento e normalização das razões recebidas antes de expor a métricas/labels (recomendado hardening geral, com fallback `worker_requested`).

### 7.2 Comportamentos a garantir por teste

1. Um worker com `Recycle=true` **não** recebe a requisição seguinte.
2. A resposta do request em curso não é alterada pelo motivo de reciclagem.
3. A geração substituta sobe segundo política existente; o evento é **planned recycle**, não `crash`.
4. A falha na substituição é observável e respeita readiness/min-ready; o Guard não contorna regras de restart/backoff.
5. Quando houver muitos findings, o sistema evita reciclar todos os workers simultaneamente; incorporar limite operacional/sinalização antes de ativar `enforce` em produção.
6. Confirmar semântica de `Release` versus escrita HTTP em teste real para detectar race/fechamento prematuro; não assumir atomicidade de flush da resposta ao cliente.

### 7.3 Pós-MVP (ADR separado)

Somente se as razões de reciclagem forem insuficientes, desenhar uma extensão **versionada** do protocolo para `guard_status` ou um canal separado de telemetria. **Não** enviar listas de findings pelo envelope HTTP na primeira versão.

---

## 8. CLI e integração no Durin’s Forge

### 8.1 `durin worker:audit` (MVP)

Execução **local/offline**, usando app de teste ou fixture e `InMemoryBridge`, não endpoint real de produção. O comando descobre a aplicação consumidora, carrega configuração, executa cenário predefinido e devolve findings. Não inicia Eregion automaticamente.

```bash
vendor/bin/durin worker:audit --scenario=tests/WorkerGuard/UserContextScenario.php
vendor/bin/durin worker:audit --scenario=tests/WorkerGuard/UserContextScenario.php --format=json
vendor/bin/durin worker:audit --scenario=tests/WorkerGuard/UserContextScenario.php --strict
```

**Contrato:**

- `--scenario=PATH` obrigatório para o MVP; não adivinhar endpoints de autenticação ou gerar identidades reais.
- `--format=text|json` (default `text`). `--strict` torna finding de severidade `warning` ou `critical` impeditivo.
- Exit codes: `0` = testes concluídos sem violações relevantes; `1` = findings acima do limiar ou assertions falhas; `2` = erro de uso/bootstrap/configuração; distinguir falha de infraestrutura de violação.
- JSON com schema versionado `schema_version: "worker-guard/1"`, campos `summary`, `findings`, `probe_coverage`, `runtime`, `result`; `findings` ordenados deterministicamente; sem conteúdo de usuários.
- O cenário precisa ser reproduzível e **isolado**: duas requisições distintas em um mesmo worker (A e B), mais controle em dois workers independentes; assertions de saída apenas com dados sintéticos.
- Proteger paths relativos contra escape da raiz do projeto; paths absolutos só se explicitamente autorizados. Não executar cenários de fontes não confiáveis.

### 8.2 `durin worker:inspect` (fase seguinte)

Consulta read-only de **artefato de auditoria local** ou métricas agregadas (não anexar ao Eregion via socket direto). Evitar prometer inspeção de memória viva remota. Exemplo proposto:

```bash
vendor/bin/durin worker:inspect --report=var/worker-guard/latest.json
```

`inspect` não é requisito para fechar o MVP HTTP.

### 8.3 Localização proposta dos arquivos

```text
mithrilphp/
  src/Runtime/Guard/
    WorkerGuard.php
    NullWorkerGuard.php
    GuardMode.php
    GuardCycleContext.php
    GuardDecision.php
    GuardFinding.php
    GuardProbe.php
    Probe/ScopedContainerProbe.php
    Probe/ExplicitStaticStateProbe.php
    Probe/MemoryTrendProbe.php
    Policy/GuardPolicy.php
    Report/GuardReport.php
  tests/Unit/Runtime/Guard/
  tests/Integration/Runtime/Guard/

durins-forge/
  src/Console/Commands/WorkerAuditCommand.php
  src/Tooling/WorkerGuard/
    ScenarioRunner.php
    GuardReportRenderer.php
  tests/Unit/Tooling/WorkerGuard/
  tests/Integration/WorkerGuard/
  docs/specs/SPEC-WG-001-worker-guard.md

eregion/
  internal/worker/...   # modificar só o necessário para validação/observabilidade
  internal/telemetry/... # ajustar cardinalidade se necessário
  tests/integration/... # reciclagem planejada end-to-end
```

Nomes são sugestão; priorizar convenções presentes em cada repositório e evitar dependências cíclicas.

---

## 9. Configuração proposta

**No app consumidor**: `config/worker_guard.php` gerenciado pelo Durin (não escrever arquivos na pasta `vendor/`).

```php
<?php

return [
    'mode' => getenv('WORKER_GUARD_MODE') ?: 'off',
    'max_probes_per_cycle' => 8,
    'sample_every_n_requests' => 10,       // aplicado somente a probes amostrados
    'report_path' => 'var/worker-guard',
    'policy' => [
        'recycle_on_verified_state_violation' => false,  // opt-in mesmo em enforce
        'recycle_on_suspected_memory_growth' => false,  // sempre false no MVP
    ],
    'static_watchpoints' => [
        // Exemplo sintético, somente propriedade com contrato de request lifetime:
        // ['class' => App\Security\UserContext::class, 'property' => 'userId', 'lifetime' => 'request'],
    ],
];
```

- Config inválida: `doctor`/CLI deve falhar com diagnóstico seguro; em HTTP production, seguir política de ativação explícita (default OFF) e não iniciar com config insegura aplicada parcialmente.
- `sample_every_n_requests` não se aplica às verificações estruturais obrigatórias de cleanup, mas aplica-se a probes de memória.
- Não exigir `ext-msgpack` no CLI offline se a auditoria usa apenas `InMemoryBridge`; respeitar dependências instaladas do projeto consumidor.
- Em `enforce`, mudanças operacionais requerem opção deliberada e rollback fácil para `observe`/`off`.

---

## 10. Esquema de Finding e relatório

Exemplo **ilustrativo** (campos não incluem valores sensíveis):

```json
{
  "schema_version": "worker-guard/1",
  "result": "findings",
  "runtime": {"engine": "mithril", "mode": "audit", "scope": "http"},
  "summary": {"cycles": 2, "probes_executed": 2, "warnings": 1, "criticals": 0},
  "probe_coverage": [
    {"id": "scoped_container", "status": "covered"},
    {"id": "static_auth_context", "status": "covered"}
  ],
  "findings": [
    {
      "code": "guard_state_retained",
      "probe_id": "static_auth_context",
      "severity": "warning",
      "confidence": "observed",
      "scope": "http",
      "safe_summary": "A watched request-scoped static property differs from baseline after cleanup",
      "recommendation": "Use request-scoped context instead of mutable static state"
    }
  ]
}
```

**Privacidade:** sem IPs, e-mails, IDs de usuários, tokens, sessão, valores observados, query params ou stack traces brutos. `report_path` é local, permissões restritas, rotação e retenção configuráveis. Relatório nunca entregue por endpoint HTTP de produção por default.

---

## 11. Cenários de aceitação / matriz de testes

### 11.1 Unit — MithrilPHP

| ID | Cenário | Resultado obrigatório |
|---|---|---|
| WG-U01 | Guard `off` | nenhum probe roda; sem mudança de resposta ou reciclagem |
| WG-U02 | Scope regular | `endScope` ocorre exatamente uma vez; zero finding de cleanup |
| WG-U03 | State watchpoint limpo | valores de teste restauram ao baseline após A/B; zero finding |
| WG-U04 | Static residual entre A e B | finding estruturado sem valor observado; `audit --strict` falha |
| WG-U05 | Singleton persistente intencional | não gera violação quando fora de watchpoints request-scoped |
| WG-U06 | Exception em `handle` | resposta 500 atual preservada; `endScope` sempre chamado |
| WG-U07 | Exception no probe | não derruba HTTP em `observe`; gera erro operacional sanitizado |
| WG-U08 | Falha `endScope` | prioridade da falha original preservada |
| WG-U09 | Tendência de memória | warning suspeito; **não** recycle automático |
| WG-U10 | Guard verificado em `enforce` | `recycle=true` e razão allowlisted, mas só após concluir request |
| WG-U11 | Razões simultâneas | regras de precedência preservadas e motivo alternativo auditável |
| WG-U12 | Dados sensíveis sintéticos | NÃO aparecem em stdout/stderr, JSON, métricas nem logs |

### 11.2 Integration — MithrilPHP + Eregion

| ID | Cenário | Resultado obrigatório |
|---|---|---|
| WG-I01 | A → B no mesmo processo | estado request-scoped registrado não vaza para B quando app está correta |
| WG-I02 | A deixa static residual de propósito | guard identifica e classifica sem exibir o valor |
| WG-I03 | Sinal de reciclagem | worker não recebe C; geração nova atende C |
| WG-I04 | `RecycleReason=guard_state_violation` | `Release` usa caminho planejado e contador com baixa cardinalidade |
| WG-I05 | Fail no envio de resposta | erro de transporte prevalece; nenhuma resposta duplicada |
| WG-I06 | Carga concorrente | não ocorre reuse de worker em draining; throughput/p95 comparáveis a baseline |
| WG-I07 | Eregion antigo compatível | metadata padrão EREGION/1 continua interpretável |
| WG-I08 | `guard` off | consumo e comportamento equivalentes ao baseline dentro de ruído |

### 11.3 CLI — Durin

| ID | Cenário | Resultado obrigatório |
|---|---|---|
| WG-C01 | `worker:audit --scenario=...` fixture saudável | exit 0 |
| WG-C02 | fixture contaminada com `--strict` | exit 1 |
| WG-C03 | scenario ausente/inválido | exit 2 |
| WG-C04 | `--format=json` | parseable; schema_version; sorting determinístico; sem valores sensíveis |
| WG-C05 | consumer root | nada é escrito em `vendor/`; reports só no projeto consumidor |
| WG-C06 | app sem Eregion binário | auditoria offline local executa com bridge em memória |

### 11.4 Gate de não regressão

- `composer test` em MithrilPHP e Forge.
- `go test ./...`, `go test -race ./...` e testes de integração em Eregion; corrigir o CI pré-existente antes de atribuir regressões ao Guard.
- API HTTP, 4xx/5xx, timeout, request count, `recycleReason`, graceful shutdown, recovery e worker generations existentes sem alteração involuntária.
- WorkerGuard desativado por default sem novas dependências de produção e sem serialização de PII.

---

## 12. Gates de desempenho e medição

**Não alegar zero overhead**. Medir no mesmo host/container e código de aplicação:

| Cenário | Comparação |
|---|---|
| Baseline | Mithril + Eregion, Guard `off` |
| Observe | `observe`, probes leves e amostragem documentada |
| Enforce | `enforce`, probes verificados e volume conhecido |
| Audit | apenas testes offline; não faz parte de benchmark de produção |

Coletar: req/s, p50/p95/p99, RSS por processo, `memory_get_usage(false/true)`, CPU, reciclagens por motivo, duração do hook, erro dos probes e número de findings. Testar warm-up, carga estável e saturação. Guard `off` deve evitar alocações/hashes extras por request além do custo mínimo da interface no-op.

**Critério inicial proposto (alvo, não resultado):** throughput e p95 de `observe` com degradação **até 5%** em cenário de referência com probes leves e limitação de amostragem, medidos em várias rodadas e com intervalo de variância. Se ultrapassar, investigar/otimizar ou reduzir probes. Não estabelecer ganho absoluto sem benchmark reproduzível.

---

## 13. Segurança, limites e falhas

1. **Múltiplos tenants/usuários:** não deduzir que snapshot de uma propriedade garante isolamento de requisições. Validar comportamento A/B no mesmo worker com fixtures e IDs sintéticos; registrar **cobertura parcial**.
2. **Static state:** o Guard detecta apenas watchpoints explícitos. Uma classe desconhecida/extension/native memory pode reter estado invisível ao Guard.
3. **Dados privados:** logar códigos e métricas; não dump de estado. No audit local, detalhes sensíveis também ficam proibidos por padrão.
4. **Recursos intencionalmente persistentes:** singleton/cache/pool não é automaticamente bug. Contrato `lifetime=request|worker` em watchpoints, exceções/allowlist auditáveis.
5. **Falha na instrumentação:** em `observe/enforce`, conter exceção do probe; erro não deve desabilitar cleanup. Em `audit` erro invalida cenário com exit de uso/infra apropriado.
6. **Memória:** crescimento de `memory_get_usage(true)` sozinho não é evidência conclusiva de leak; observar tendência e ciclo de vida do processo.
7. **Falhas em massa:** aplicar opt-in e rollout gradual do `enforce`; sem tempestade de reciclagens. Modo `observe` é fallback operacional imediato.
8. **DoS por cardinalidade:** nenhum label dinâmico derivado de código de usuário, endpoint, request ID ou mensagem de exceção.
9. **Hooks fora de ordem:** não executar `after` quando `before` não concluiu corretamente sem sinalizar estado de coleta incompleto.
10. **Escopo de erro:** política de Guard não deve sobrescrever motivo pré-existente de `ScopeCleanupFailure` nem mascarar falha de transporte.

---

## 14. Plano de implementação por PRs e dependências

### Phase 0 — Baseline + ADR curta (obrigatório)

**MithrilPHP:** registrar snapshot de comportamento atual de `Worker`, metadata, `RecyclingPolicy`, exceções e bridge. Criar ADR com limites da detecção e política de opt-in.  
**Eregion:** estabilizar testes correntes e registrar comportamento de `Release`/recycles.  
**Forge:** conferir composer compatibility e convenções de CLI.  
**Gate:** nenhuma mudança de protocolo ou alteração visível no Guard `off`.

### Phase 1 — Mithril Guard Core (MVP prioritário)

- DTOs, `NullWorkerGuard`, executor de probes e `GuardPolicy`.
- `ScopedContainerProbe` e `ExplicitStaticStateProbe` (test fixtures antes de apoio geral).
- Hooks bem posicionados no `Worker.php`; razões allowlisted no `WorkerMetadata`.
- Testes WG-U01..U12 (quando aplicáveis) e teste de compatibilidade de bridge.

**Gate:** detectar static residual conhecido e reciclar apenas sob opt-in; sem PII/sem mudança de resposta.

### Phase 2 — Durin Audit CLI (MVP demonstrável)

- `worker:audit` com scenario runner usando `InMemoryBridge`, JSON/text, código de saída.
- Scenarios sintéticos positivos/negativos como exemplo.
- Config no root consumidor, nenhum write em vendor.

**Gate:** comandos WG-C01..WG-C06; onboarding em README com exemplo reproduzível.

### Phase 3 — Eregion integration hardening

- Testar semântica de `Release` para `guard_state_violation`, geração substituta e métricas.
- Limitar allowlist/cardinalidade de razões se necessário; sem mudar `EREGION/1`.
- Teste end-to-end com Eregion real e PHP fixtures, geração e recycle.

**Gate:** WG-I01..WG-I08; CI verde, restart normal, sem resposta duplicada.

### Phase 4 — Production Observe

- `MemoryTrendProbe`, métricas de tempo/erros da instrumentação e documentação de operação.
- Probes apropriados para produção, bounded e amostrados.
- Benchmarks comparativos `off` vs `observe`, rollout em canário.

**Gate:** nenhum vazamento em relatórios, métricas estáveis, degradação mensurada e aceitável.

### Phase 5 — Enforce controlado / JobWorker (fora do MVP)

- Habilitar opt-in seguro para findings `critical/verified`, com proteção contra recycle storms.
- ADR separada para `JobWorker`: hook depois de `ack/retry/reject`, proteção de idempotência; nunca reciclar antes de confirmar resultado no broker.
- Se houver Eregion supervisionando consumers, avaliar canal de saúde sem acoplar job protocol ao HTTP.

**Gate:** teste de exactly-once **não** deve ser prometido; testar pelo menos retry/ack/reject e ausência de perda de jobs sob recycle/termination.

---

## 15. Definition of Done

A primeira versão só pode ser marcada **DONE** se:

- [ ] `off` por default e compatibilidade do comportamento atual demonstrada por testes.
- [ ] CLI `worker:audit` executa dois requests artificiais no mesmo worker e evidencia caso limpo e contaminado.
- [ ] Um watchpoint explícito produz finding sanitizado, determinístico e classificável.
- [ ] Falha de probe não bloqueia resposta em `observe`.
- [ ] `endScope` continua sendo executado e sua falha original não é mascarada.
- [ ] `enforce` só solicita reciclagem de finding **verificado** com política explícita.
- [ ] `recycle` + `recycle_reason` existentes continuam wire-compatible com EREGION/1.
- [ ] Eregion comprova que worker reciclado não aceita nova requisição e que a geração substituta assume.
- [ ] Sem PII, token, cookie, contexto de auth ou dados do negócio em relatórios/métricas.
- [ ] CI de MithrilPHP, Eregion e Forge verde e testes de integração documentados.
- [ ] Benchmark público com metodologia e limites honestos; nenhuma alegação não validada.
- [ ] READMEs e ADRs atualizados para diferenciar `watchpoint` de garantia geral de isolamento.

---

## 16. Prompt curto para agente orquestrador

> Implemente a SPEC-WG-001 **como referência funcional absoluta**, respeitando o código corrente e as fronteiras MithrilPHP/Eregion/Durin. Comece por Phase 0 e Phase 1 no MithrilPHP; reutilize `WorkerMetadata.recycle/recycleReason`, `RecyclingPolicy`, `beginScope/endScope` e `Eregion Pool.Release`. Não crie novo supervisor, novo workload nem protocolo. Entregue PRs pequenos com testes e evidências por gate. A auditoria é opt-in; no MVP detecta apenas estado **explicitamente registrado** e não pode vazar dados sensíveis ou alterar respostas HTTP. Em cada fase, informe arquivos alterados, testes executados, divergências e limitações; não avance se o gate atual falhar.

---

## 17. Referências para implementação

- [MithrilPHP Worker](https://github.com/EreborCodeForge/mithrilphp/blob/main/src/Runtime/Worker.php)
- [MithrilPHP Container](https://github.com/EreborCodeForge/mithrilphp/blob/main/src/Container.php)
- [MithrilPHP EregionBridge](https://github.com/EreborCodeForge/mithrilphp/blob/main/src/Runtime/Eregion/EregionBridge.php)
- [MithrilPHP RecyclingPolicy](https://github.com/EreborCodeForge/mithrilphp/blob/main/src/Runtime/Recycling/RecyclingPolicy.php)
- [Eregion protocolo](https://github.com/EreborCodeForge/eregion/blob/main/internal/protocol/messages.go)
- [Eregion Pool](https://github.com/EreborCodeForge/eregion/blob/main/internal/worker/pool.go)
- [Eregion Workload](https://github.com/EreborCodeForge/eregion/blob/main/internal/workload/model.go)
- [Durin Forge Console](https://github.com/EreborCodeForge/durins-forge/blob/main/src/Console/Application.php)

**Final:** WorkerGuard é uma camada de auditoria e governança do runtime persistente, não uma promessa de isolamento perfeito, não uma implementação de workloads e não uma substituição do supervisor existente.
