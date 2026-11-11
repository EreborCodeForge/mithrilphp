# SPEC-WG-002 — WorkerGuard V1.1: Performance & Safety Hardening

**Status:** PROPOSED / aguardando implementação  
**Versão da especificação:** 1.0.0  
**Data-base:** 2026-11-10  
**Tipo:** Especificação técnica cross-repository, posterior à SPEC-WG-001  
**Repositórios:** `EreborCodeForge/mithrilphp` (dono do Guard), `EreborCodeForge/eregion` (supervisão), `EreborCodeForge/durins-forge` (CLI/DX)  
**Referências:** `SPEC-WG-001`, `docs/worker-guard.md`, `docs/adr/0001-worker-guard.md` no checkout da implementação.

> **Missão:** tornar o WorkerGuard V1 seguro de ativar gradualmente, com overhead mensurado, critérios de verificação rigorosos, configurações diagnosticáveis e proteção contra cascatas de reciclagem — sem mudar a semântica do PHP, sem reivindicar isolamento arbitrário e **sem alterar o protocolo EREGION/1**.

---

## 0. Mandato para o agente executor

1. Trate esta spec como **referência funcional obrigatória**, mas inspeccione o estado **atual da branch de trabalho** e os testes antes de alterar qualquer arquivo. Os nomes e exemplos abaixo são contratos-alvo, não afirmações sobre APIs que já existem.
2. A SPEC-WG-001 continua válida. Esta spec **substitui especificamente** suas expectativas de performance e complementa sua segurança operacional, sem reabrir o escopo de detecção universal.
3. Não comece por `ResourceProbe`, `worker:inspect`, `JobWorker`, `#[Workload]`, alterações de broker, runtime autopilot ou protocolos novos. Essas funcionalidades ficam fora desta entrega.
4. Não altere o princípio **`off` por padrão**, nem o opt-in explícito para `observe`/`enforce`, nem a flag `recycle_on_verified_state_violation`.
5. **Não reduza a segurança para ganhar performance:** sampling nunca pode transformar uma verificação obrigatória do modo `enforce` em eventual. Se um probe é caro demais, ele não pode ser elegível para enforcement até ser otimizado ou substituído.
6. A decisão final de reciclagem já definida pelas políticas existentes prevalece sobre a decisão do Guard. `scope_cleanup_failure` mantém prioridade máxima. Registre razões secundárias apenas em relatório/telemetria segura.
7. Não simule que o Eregion pode conhecer findings internos do PHP. Sem nova telemetria interoperável, o supervisor só sabe que houve solicitação de reciclagem e sua **razão finita**.
8. Faça alterações pequenas e testáveis. Ao final de cada fase, registre alterações, comandos executados, resultados, divergências e riscos. Não marque uma fase como concluída se seu gate falhou.

### 0.1 Evidência recebida do MVP

**Resultados reportados pelo agente anterior — ainda exigem reprodução independente:**

- MithrilPHP: `composer test`, **154 testes passando**, incluindo WG-U01–U12 e A→B no mesmo processo.
- Durin’s Forge: `composer test`, **184 testes passando**, incluindo `worker:audit` com saídas 0, 1 e 2.
- Eregion: `go test ./...` passando; `go test -race` não executado pela ausência de `gcc`/cgo.
- Loop PHP vazio, 2.000 requests: ~**100 mil req/s** em `off` versus ~**46 mil req/s** em `observe`; redução aproximada de **54–55%** de throughput; hook ~**8 µs/request**.
- Benchmark acima **não inclui o Eregion**; a comparação é microbenchmark com carga mínima e tamanho de amostra insuficiente para generalização.
- `audit` só em CLI; no worker HTTP, `audit` ou configuração inválida são tratados como `off` no MVP.
- Static watchpoint usa hash com salt; não guarda valor; não há `ResourceProbe`.
- Eregion `Pool.Release` processa `recycle=true` como reciclagem planejada; a reason `guard_state_violation` deve permanecer identificável.

**Não assumir que o conteúdo de documentação recém-criado já foi publicado em `main`.** Validar o checkout de desenvolvimento, versão do Composer, tags e divergências no gate inicial.

---

## 1. Objetivos e indicadores de sucesso

| ID | Objetivo | Indicador / critério de aceite |
|---|---|---|
| WG11-P01 | `off` com caminho de execução mínimo | Sem instanciar probes, calcular hash ou produzir findings por request; sem regressão material em benchmark controlado; meta de até **2%** em throughput/p95 versus baseline anterior sem Guard, após considerar variação experimental |
| WG11-P02 | Reduzir custo de `observe` | Hook medido separadamente, por tipo de probe; meta exploratória de **p50 ≤ 3 µs** para configuração leve de referência (a validar na mesma máquina); não tratar como SLA universal |
| WG11-P03 | Benchmark realista | Para **cenário HTTP representativo previamente fixado**, objetivo **≤ 5% de degradação** em throughput e p95 em `observe` com configuração leve; relatar intervalo de variação, inclusive se não atingir |
| WG11-P04 | Medição ponta a ponta | Experimento HTTP com **Eregion → Mithril**, configurações idênticas, warm-up, concorrência, logs e resultados reproduzíveis |
| WG11-S01 | Evitar tempestades de reciclagem | `guard_state_violation` repetido ativa circuit breaker **por workload** no Eregion; worker suspeito nunca volta ao pool; sem loop ilimitado de restarts |
| WG11-S02 | Degradação segura | Em abertura do breaker, preservar workers saudáveis; não admitir requisição em worker já suspeito; se capacidade zerar, recusar via caminho HTTP existente, sem falsa prontidão |
| WG11-S03 | Config inválida visível | Modo HTTP continua em fallback seguro `off`, **com diagnóstico explícito**; `durin doctor --strict` retorna erro; `worker:audit` retorna 2 quando inválido |
| WG11-S04 | Verificação de findings | `verified` exige evidência definida e estável; alteração simples de hash não implica, por si só, violação de escopo |
| WG11-O01 | Telemetria sem PII | Contadores/latências/logs sanitizados e label cardinality bounded; nenhuma propriedade estática ou hash bruto em relatório |
| WG11-Q01 | Não regressão | Todas as suítes existentes passam; novos testes de concorrência, timeouts, guard-off e integração; race tests quando ambiente permitir |

**Natureza dos limites:** 2%, 3 µs e 5% são **metas de engenharia sugeridas**, não fatos de performance. Registrar hardware, versão PHP/Go, jit/opcache, parâmetros e variabilidade; ao não atingir, abrir finding de performance e não declarar atingido. Não mascarar o microbenchmark vazio com uma média de endpoint lento. Um baseline observado não substitui o número da tabela.

### 1.1 Finding WG11-P02 — 2026-11-10

O critério permanece **p50 ≤ 3 µs** para a configuração leve de referência. Ele **não foi atingido** e não foi rebaixado.

Baseline observado do hook `observe_light`, no mesmo host e no mesmo runner da faixa de 8441 ns (Windows 10, PHP 8.5.10, 100.000 requests, 10 rodadas, nenhuma descartada): **5,3 µs** (p50 5326 ns, faixa 5194–6123 ns, `bench/results/hook-opt.json`). A repetição `bench/results/hook-opt-recheck.json` ficou em p50 5374 ns (5125–6527 ns). Ela confirma o baseline; não cria outro requisito. A faixa anterior a estas duas alterações era 7336–9136 ns.

O menor número compatível com a cobertura atual — probes isolados mais os dois pares de `hrtime` — é cerca de 2,7 µs. O hook medido ainda carrega cerca de 2,6 µs de laço, `try`/`finally` e ciclo sem finding. Chegar a 3 µs sem cortar scoped, memória, os relógios do hook ou a leitura do watchpoint não ocorreu. Metodologia, pares HTTP e o que continua aberto estão em `docs/worker-guard-v1.1-benchmark.md`.

---

## 2. Fronteiras arquiteturais

```text
Durin CLI / doctor
    |  lê configuração e relatório offline, valida contratos
    v
MithrilPHP WorkerGuard (único dono dos probes / finding / política)
    |   execução HTTP: off | observe | enforce
    |   recycle=true, recycle_reason=guard_state_violation
    v
EREGION/1 EXISTENTE (sem novo campo, versão ou tipo de frame)
    v
Eregion Pool.Release + GuardRecycleCircuitBreaker
    |   HTTP worker é retirado após resposta concluída
    |   supervisor limita novas tentativas de bootstrap
    v
pool saudável / degraded / unavailable segundo sinais reais
```

**Invariantes de ownership**

- **MithrilPHP:** `GuardMode`, probes, watchpoints, hash/HMAC, veredito, amostragem de `observe`, medição local e sanitização; mantém `Worker.php` e `RecyclingPolicy`.
- **Eregion:** gerações, start/stop, draining, restart/backoff, circuit breaker por workload e métricas agregadas; não inspeciona objetos PHP.
- **Durin’s Forge:** resolve/valida configuração, oferece `worker:audit` e `doctor --strict`, apresenta resultados; não manda SIGTERM nem gerencia PIDs.
- `off` e configurações legadas continuam funcionando; build/release sem misturar alterações incompatíveis. Não modificar a semântica de `JobWorker` nesta spec.

---

## 3. Fase A — Baseline, profiling e reproduzibilidade

### A.1 Inventário obrigatório

No **MithrilPHP**, identificar no código atual:

- ponto real em que Guard/Probe é instanciado;
- `before()`/`after()` e ordem relativa a `beginScope()`, `handle()`, `endScope()`;
- implementação de static watchpoint, algoritmo e lifetime do salt;
- parsing/fallback de configuração e onde o `off` é escolhido;
- decisões de recycle atuais, com prioridade `scope_cleanup_failure` e reason de política existente;
- existência ou não de probes iniciais sem watchpoints (deve custar quase nada).

No **Eregion**, mapear `Pool.Release`, `monitor`, `scheduleRestart`, `SetDesired`, `TryAcquire`, `Acquire`, readiness, métricas e a serialização/validadores de `ResponseMeta`. Confirmar que `guard_state_violation` não sofre normalização incorreta. Mapear `min_ready`, restart backoff e os caminhos de saturação HTTP.

No **Durin**, validar `worker:audit`, saídas 0/1/2, `doctor --strict`, configuração e dependência da versão publicada de Mithril.

### A.2 Perfil de performance

Criar benchmark automatizável, salvo em `bench/` ou estrutura vigente, incluindo obrigatoriamente:

1. **Loop vazio:** mesmo bootstrap e bridge; 2.000 requests anteriores **apenas como baseline histórico**, repetir com >=100.000 ciclos por execução quando viável; mínimo 10 rodadas emparelhadas por modo, ordem alternada ou randomizada.
2. **HTTP JSON leve:** resposta JSON pequena, sem banco, com Eregion.
3. **HTTP representativo:** endpoint com serialização, roteamento, DI, middleware e acesso a banco ou dependência *mockada/isolada*, com latências fixas para não confundir rede externa; documentar qual cenário representa o produto.
4. **Sob saturação:** limite de workers e concorrência crescente; medir backpressure, 503 e latência da fila.
5. **State violation:** benchmark de cenário com watchpoint detectando violação, separado do caminho saudável.

Variantes: `off`, `observe` sem probe, `observe` watchpoint leve, `observe` com sampling configurado, `enforce` sem violação, `enforce` com violação em teste controlado. Não misturar a penalidade de reciclagem com overhead por request saudável.

**Métricas mínimas:** throughput, p50/p95/p99, CPU, RSS, memória PHP alocada/real, tempo do hook, quantidade de inspeções, findings, reciclagens, recusas HTTP e taxa de erros. Publicar comando exato, configuração, versões, ambiente e resultado bruto. Não descartar rodadas ruins sem critério pré-definido.

### A.3 Gate A

- Um benchmark reproduz os resultados atuais dentro de faixa plausível ou explica a divergência.
- Relatório separa **custo do Guard** de **custo do Eregion** e do endpoint.
- Captura baseline `off` para comparação de regressão posterior.
- Runner marca `N/A` quando requisito de ambiente não existe; não transforma teste não executado em aprovação.

---

## 4. Fase B — Fast path e custo de observação (MithrilPHP)

### B.1 Fast path de `off`

**Comportamento obrigatório:**

- Resolver configuração **uma única vez** por worker (ou na inicialização, respeitando a forma atual de boot).
- Em modo `off`, não executar `GuardProbe`, reflexão, snapshot, hash/HMAC, coleta específica de estado, callbacks nem serialização de finding.
- Escolher entre loop sem guard ou um check constante e estável **fora** das partes quentes, desde que não multiplique alocações por request. Manter o mesmo comportamento de scope/recycle já existente.
- Não alterar assinaturas públicas para forçar o Guard em integrações que não o usam.

### B.2 Pré-resolução dos watchpoints

- Resolver metadados de reflection, handlers, identificadores e capacidades ao iniciar o worker; rejeitar registros inválidos de forma diagnosticável.
- Criar um **plano imutável de probes** (`ProbeExecutionPlan`, nome ilustrativo) com número máximo de watchpoints configurável e limites de custo.
- Não armazenar referência forte ao Request/Response, usuário ou objetos `scoped` entre ciclos.
- Minimizar concatenar strings, criar DTOs e gerar mensagens no caminho sem finding; lazy materialization para o relatório.
- Cachear metadados e callbacks estáveis, **não** snapshot de valor sensível entre usuários além da comparação estritamente necessária.

### B.3 Sampling: somente observacional

- `observe`: permitir `sample_every_n_requests` **somente** para probes elegíveis (`sampleable=true`) e heurísticas de memória; execução determinística por contador para facilitar depuração. Se `n=1`, todas as requests; `n=10`, uma em cada dez.
- `enforce`: **não amostrar** qualquer probe cujo resultado possa gerar `critical/verified` e `recycle=true`. Se um watchpoint exige sampling para cumprir orçamento, reclassificá-lo como **observação apenas** ou rejeitar configuração de enforcement para ele.
- `off`: nenhum hook.
- `audit` CLI: executa o conjunto completo necessário para a sequência A→B; sampling não pode omitir uma etapa da prova.
- Nunca ativar `enforce` por amostragem nem promover automaticamente observações de memória a veredito `verified`.

### B.4 Hash e evidência

- Avaliar hash com salt atual: para comparações dentro do processo, usar chave aleatória **ephemeral** gerada ao boot e HMAC para material de baixa entropia quando possível, sem imprimi-la, salvá-la ou expô-la por metrics.
- **Não salvar ou renderizar valor/hash de watchpoint**. Guardar apenas identificador allowlisted, severidade, confiança, categoria de mudança e informação estrutural segura.
- Conservar semântica de watchpoint: estado expected-reset/baseline explícito difere de mudança permitida e singleton intencional. Um hash diferente sozinho é **observação**, não prova de violação de isolamento.
- `verified` só quando a regra de watchpoint exige limpeza/restauração, o estado pós-request viola a regra, e a evidência pode ser reproduzida no cenário determinístico apropriado. Documentar se `verified` reflete **violação local de contrato** ou **contaminação A→B confirmada**; não usar os termos como sinônimos.
- Nunca prometer detectar estáticos não registrados, estado de extensão, streams/conexões/transações ou memória nativa.

### B.5 Custo limitado e falha de probe

- Cada probe tem custo reportável, pelo menos timing monotônico agregado; limites de quantidade e duração devem evitar loops internos não limitados.
- Exceção do probe em `observe` gera finding operacional sanitizado e contador, **sem interromper resposta**; `enforce` não recicla exclusivamente por erro de instrumentação, a menos que uma política separada e explicitamente definida no futuro prove risco.
- `audit --strict` retorna falha adequada se a instrumentação não puder executar a prova.
- Overhead de instrumentação deve poder ser desligado sem reinstrumentar código de negócio.

### B.6 Gate B

- Teste demonstra zero execução de probe/hash/reflection em `off` por request.
- `observe` amostrado reduz número de avaliações conforme configuração; `enforce` obrigatório executa todo ciclo, mesmo com `sample_every_n_requests > 1`.
- Razões de reciclagem existentes e `scope_cleanup_failure` não mudam.
- Repetir benchmarks; se metas não forem atingidas, relatar custo por probe e análise de flame/profile, sem esconder números.

---

## 5. Fase C — Anti-storm de reciclagem (Eregion)

### C.1 Problema

Um watchpoint mal configurado ou estado persistentemente indevido pode disparar uma violação em toda nova geração do worker. O `Pool.Release` recicla corretamente cada worker, mas **reiniciá-lo indefinidamente** pode gerar consumo elevado e indisponibilidade. `MinReady` não é proteção contra esse ciclo.

### C.2 Princípio de segurança

**Nunca devolver à fila `idle` um worker para o qual houve `guard_state_violation` verificado.** O supervisor pode pausar **novas tentativas de boot**; não pode reutilizar um worker previamente sinalizado como comprometido apenas porque o orçamento de restarts esgotou.

Quando não existem workers elegíveis, seguir o caminho **já existente** de recusa/backpressure, com readiness degradada/inválida de acordo com o estado verdadeiro. Sem fallback silencioso para PHP inseguro.

### C.3 Circuit breaker por workload

Criar componente no Eregion como `GuardRecycleBreaker` (nome sugerido; adaptar ao package atual), invocado **somente** para razões conhecidas do Guard e sem alterar reciclagem por `max_requests`, `memory_limit`, shutdown, deploy etc.

**Estados:**

- `closed`: reinício normal; contabiliza ocorrências de `guard_state_violation` numa janela móvel.
- `open`: protege contra loops: as gerações sinalizadas já foram aposentadas; **novas tentativas de bootstrap por violação do Guard** ficam bloqueadas/limitadas, sem impedir workers saudáveis em operação.
- `half_open`: após cooldown, permite **uma** tentativa controlada de nova geração no workload; demais tentativas aguardam resultado dessa tentativa.

**Default proposto (configurável):**

```yaml
# Exemplo de intenção; integrar ao schema de configuração REAL do Eregion.
worker_guard_recycle_safety:
  enabled: true
  reasons: [guard_state_violation]
  max_recycles: 3
  window: 30s
  cooldown: 60s
  half_open_max_probes: 1
  recovery_successful_requests: 20
  recovery_min_uptime: 30s
```

Os números são defaults **propostos**, sujeitos a teste. O sistema deve permitir valores mínimos/máximos validados, e comportamento legado ao desligar a política. O breaker controla boot/restart **decorrente da razão do Guard**; não é um rate limiter genérico de requisições.

**Transições-alvo:**

1. `closed` recebe `guard_state_violation` em `Pool.Release`: recicla o worker após resposta, registra evento e agenda nova geração se ainda dentro do orçamento.
2. Ao alcançar `max_recycles` dentro de `window`, entra em `open`. Nenhum worker marcado retorna à fila de disponíveis; tentativas de restart atribuídas ao Guard ficam represadas.
3. Após `cooldown`, concede exatamente **um token de tentativa** por workload em `half_open` (respeitar cancelamento de contexto, shutdown e desired workers). Se startup falhar, tratar como falha e reabrir com backoff, sem loop agressivo.
4. O êxito não é `PID iniciado`: deve aguardar `recovery_successful_requests` **requisições concluídas no mesmo worker** sem violation **e** `recovery_min_uptime`. Só então `closed` e orçamento reiniciado. Caso de baixa carga: deixar half-open até satisfazer o sinal ou permitir política de recuperação conservadora documentada; **não** afirmar recuperação com zero requests.
5. Se o worker de prova emitir `guard_state_violation`, retirar do pool, reabrir e reiniciar cooldown. Mantém 503/queueing quando não há outros workers saudáveis.
6. Em concorrência, eventos simultâneos não podem exceder `half_open_max_probes`; proteger transição com mutex/atomic, sem bloquear I/O de processo sob lock.
7. Não cruzar limites entre workloads; uma falha do workload A não desabilita o B.
8. Um worker de prova deve permanecer apto a processar **somente se não há evidência de violação até aquele momento**; se sinalizado, nunca deve retornar a idle. Não usar como prova de isolamento completo.

**Nota de desenho:** Evitar que `open` impeça indefinidamente a reposição dos workers saudáveis que reiniciam por motivos **não relacionados** ao Guard. No entanto, o mesmo pool não deve virar um motor de restart contínuo por simples reclassificação de reason. Determinar causalidade e permitir tratamento correto de `shutdown`, `scale-down` e crash sem enfraquecer limites existentes. Documentar escolhas de concorrência antes do código.

### C.4 Integração nos pontos existentes

- `Pool.Release`: detectar somente reason validada `guard_state_violation`, contabilizar e marcar reciclagem planejada; não alterar semântica de resposta HTTP.
- `monitor`/`scheduleRestart`: consultar breaker ao decidir quando/como iniciar uma nova geração por Guard; não mover criação de processo para Mithril.
- `SetDesired` e `Start`: reconciliar desired state sem furar breaker; documentar manual override administrativo se necessário (fora MVP, não criar endpoint público por padrão).
- `Acquire`/`TryAcquire`: nunca entregar worker em estado `draining`, aposentado ou de geração stale; manter comportamento de proteção atual.
- `Shutdown`: cancelar timers/permissões pendentes; sem goroutine vazando ou reinício após shutdown.
- `MinReady`/readiness: relatar capacidade real, mesmo que abaixo do desejado. **Não maquiar saúde** para preservar disponibilidade aparente.

### C.5 Métricas e logs do breaker

Mínimo proposto no Eregion, nomes finais seguindo convenções existentes:

```text
eregion_worker_guard_recycle_events_total{workload,reason}
eregion_worker_guard_breaker_state{workload,state}       # estado, cardinalidade fixa
eregion_worker_guard_restart_suppressed_total{workload}
eregion_worker_guard_half_open_attempts_total{workload,result}
```

- `reason` com allowlist (`guard_state_violation`, `other` se necessário); não transformar reason arbitrária enviada por PHP em label de Prometheus.
- `workload` limitado aos nomes de workloads configurados; não introduzir worker ID, path, propriedade PHP, usuário ou request ID como label.
- Reaproveitar o contador existente de reciclagens; documentar eventual duplicidade entre métricas.
- Eventos estruturados: `workload`, geração, transição, razão normalizada, contagem na janela, cooldown, **nunca** dados de aplicação.

### C.6 Gate C

- Violação contínua em todas as novas gerações atinge `open` após o limite; número de tentativas fica limitado.
- Nenhum worker comprometido volta ao pool (assertivo em testes com requisições reais).
- Workers saudáveis continuam atendendo enquanto houver capacidade; sem capacidade, caminho existente devolve erro controlado, nunca atendimento inseguro.
- Half-open permite um único candidato, inclusive sob concorrência e scale-up; sucesso requer requests saudáveis e tempo mínimo; regressão reabre.
- Shutdown cancela pending restarts; workload B independe de workload A.
- `go test ./...` e `go test -race ./...` quando ambiente oferecer gcc/cgo; se não oferecer, **registrar explicitamente não executado** e exigir CI compatível antes de merge de concorrência.

---

## 6. Fase D — Configuração, diagnostics e ergonomia (MithrilPHP + Durin)

### D.1 Parsing compartilhado

- O **MithrilPHP** é fonte de verdade dos tipos e validadores de `GuardMode`, sampling, watchpoint constraints e flag de enforcement. O **Durin** usa esse contrato/validador, não replica suas regras em paralelo.
- Validar `mode ∈ off|observe|enforce|audit` com `audit` exclusivo para CLI; `sample_every_n_requests >= 1`; limites de watchpoints; caminhos/IDs válidos; coerência `enforce` + watchpoint elegível + flag `recycle_on_verified_state_violation`.
- Qualquer opção desconhecida, inválida ou ambígua deve gerar diagnóstico seguro. Preferir lista enumerada de erros, sem ecoar valores que possam conter segredos.

### D.2 Semântica de ambientes

| Contexto | Config inválida | `mode=audit` | Observação |
|---|---|---|---|
| Worker HTTP | Fallback `off` **+ warning explícito uma vez por boot** + registro operacional | Fallback `off` + warning explícito | Nunca ativar `enforce` por correção automática |
| `durin doctor` | `WARN` e orientação | Identifica incompatibilidade HTTP | Status num formato estruturado, se suportado |
| `durin doctor --strict` | Exit não-zero (usar semântica de exit code atual) | Exit não-zero quando perfil HTTP | Diagnóstico de CI obrigatório |
| `durin worker:audit` | Exit **2** (configuração/execução inválida) | Permitido | Exit 0=sem violação, 1=findings reprovados, 2=erro, preservando MVP |

- **Não usar fallback silencioso**; expor evento sanitizado e claro: “WorkerGuard invalid configuration; guard disabled for this worker. Run `durin doctor --strict`”.
- Em produção, warning emitido **uma vez por processo/boot**, para não criar tempestade de logs.
- Não assumir que warning HTTP substitui validação de deploy: pipeline deve executar `doctor --strict` antes da publicação.
- Se a flag de enforcement está false, Guard pode observar finding critical/verified em `enforce`, mas **não** solicita reciclagem por ela; documentar precisamente.

### D.3 Release/compatibilidade

- Repositório Forge deve continuar consumindo uma versão publicada compatível do Mithril, sem dependência implícita de `../mithrilphp` em release.
- Usar dev path repository somente nos testes locais explicitamente controlados; CI inclui instalação limpa do Composer/Packagist após publicar release compatível.
- Verificar compatibilidade semântica e dependências no `composer.json`; não atualizar constraint ou publicar nova tag sem teste de consumidor em diretório vazio.
- Não renomear `worker:audit` nem mudar saídas 0/1/2.

### D.4 Gate D

- Testes unitários da matriz acima, incluindo warning exatamente uma vez.
- `doctor --strict` diagnostica `mode=audit` em worker HTTP e configurações inválidas; nenhum segredo nos erros.
- `worker:audit` preserva 0/1/2.
- Consumidor Composer limpo passa com uma tag real compatível antes de declarar publicável.

---

## 7. Fase E — Observabilidade e métricas de baixo custo

### E.1 Fonte de cada métrica

**No MithrilPHP (local ao worker):** duração/quantidade de probes, ciclos inspecionados, findings por código allowlisted, falhas de probe e configuração. Em `off`, não adicionar coleta por request para Guard.

**No Eregion (supervisor):** reciclagens normalizadas por motivo, estados e transições do circuit breaker, restarts bloqueados, saúde/capacidade real dos pools, recusas HTTP. **O Eregion não conhece findings do Mithril** pelo EREGION/1 atual; portanto não criar falsa métrica `findings_total` do lado Go.

**No Durin (offline):** JSON/text de `worker:audit` e validação do `doctor`, sem gerenciar processos.

### E.2 Saída segura de findings

Exemplo de evento (contrato orientativo):

```json
{
  "component": "mithril.worker_guard",
  "mode": "observe",
  "event": "probe_finding",
  "probe_id": "auth_context_reset",
  "code": "guard_state_retained",
  "severity": "critical",
  "confidence": "verified",
  "scope": "http",
  "request_ordinal": 1042,
  "action": "log"
}
```

Não incluir valor de contexto, conteúdo hash/HMAC, cookie, sessão, URL completa com query, Authorization, PII nem conteúdo do body. Suprimir stack traces sensíveis em produção; registrar erros de probe como código operacional seguro.

### E.3 Limites e performance de telemetria

- Logging síncrono detalhado por request **desativado** em produção; apenas findings e eventos significativos, com rate limit de log, se necessário.
- Cardinalidade fixa para `code`, `severity`, `reason`, `mode`; proibir labels derivados de classe arbitrária ou dados de request.
- Histogramas de hook podem ser locais/amostrados quando forem medidos e de custo bounded; a instrumentação de benchmark deve ser comparada separadamente de instrumentação de produção.
- Não registrar hash ou salt nem mesmo em `debug`.

### E.4 Gate E

- Snapshot de labels de métrica confirma cardinalidade bounded.
- Teste com strings-canário sensíveis sintéticas prova ausência nos logs/JSON/Prometheus.
- Repetir benchmark com telemetria no perfil real de produção.

---

## 8. Plano de testes obrigatório

| ID | Cenário | Resultado esperado |
|---|---|---|
| WG11-U01 | Guard `off` por padrão | Nenhum probe/hashing/DTO de finding executado, comportamento HTTP idêntico |
| WG11-U02 | `off` com watchpoints registrados em config | Não instanciá-los no hot path |
| WG11-U03 | `observe sample_every_n_requests=10` em probe sampleable | Ciclos executados conforme contador, nenhum recycle por finding |
| WG11-U04 | `enforce` + sampler configurado | Verificação crítica obrigatória em **todas** as requests; flag false não recicla |
| WG11-U05 | `audit` com sequência A→B | Não pula ciclo; prova determinística e redaction |
| WG11-U06 | Mudança de hash permitida | Não vira `verified` sem contrato de reset violado |
| WG11-U07 | Violação `critical/verified` | Recycle somente com `enforce` + opt-in específico |
| WG11-U08 | Política existente decide `memory_limit` e Guard acha finding | Reason da política existente preservada; finding só no relatório |
| WG11-U09 | `endScope()` falha e Guard acha violation | Prioridade `scope_cleanup_failure`, sem dupla resposta |
| WG11-U10 | Probe lança exceção | Observação registra erro seguro; request HTTP continua |
| WG11-U11 | Config inválida HTTP / `audit` em HTTP | Cai em `off` + warning **uma vez**; não ativa proteção |
| WG11-U12 | Config inválida no `doctor --strict` | Exit não-zero e mensagem sanitizada |
| WG11-U13 | CLI com inválida / finding / sucesso | Saídas 2 / 1 / 0, sem regressão |
| WG11-U14 | Segredo sintético em estático | Não aparece no log, JSON, métricas ou erro |
| WG11-E01 | 3 violações numa janela de 30 s (config exemplo) | Breaker entra `open`; reinícios por Guard contidos |
| WG11-E02 | Worker marcado guard violation | Nunca volta a `idle`, inclusive sob concorrência |
| WG11-E03 | Capacidade remanescente saudável | Continua atendendo; sem ela, resposta de indisponibilidade controlada |
| WG11-E04 | Após cooldown, 2 boots concorrentes | Só 1 half-open trial admitido por workload |
| WG11-E05 | Half-open com 20 requests limpas e uptime mínimo | Volta a `closed`, com contadores reiniciados |
| WG11-E06 | Half-open volta a violar | Reabre, retira suspeito, cooldown inicia |
| WG11-E07 | `SetDesired`/scale-up durante `open` | Não fura o limite de restart do Guard |
| WG11-E08 | Shutdown com restart pendente | Nenhuma geração criada após shutdown |
| WG11-E09 | Dois workloads | Breaker de um não afeta outro |
| WG11-E10 | Max requests/memory/shutdown | Sem regressão no fluxo de reciclagem atual |
| WG11-E11 | Reason desconhecida / não allowlisted | Normalização limitada; sem cardinalidade arbitrária |
| WG11-I01 | Eregion + Mithril + Durin ponta a ponta | Finding verificado → resposta concluída → recycle planejado → nova geração ou breaker |
| WG11-P01 | Microbenchmark vazio | Histórico vs otimizado documentado; sem reivindicar 5% universal |
| WG11-P02 | HTTP JSON leve Eregion | Reporta throughput, p95/p99 e CPU em todos modos |
| WG11-P03 | HTTP representativo Eregion | Avalia meta ≤5% sem omitir rodadas ruins |
| WG11-P04 | Saturação e violação em série | Confiabilidade, recusas, latência e retomada descritas |

**Confiabilidade:** testes do breaker com `fake clock`/relógio injetável e processo/slot fake em Go (sem `sleep` longo), testes de concorrência reais separados, cenários determinísticos de duas requisições no mesmo processo PHP, testes de integração com supervisor quando possível. Não marcar `-race` como PASS se indisponível.

---

## 9. Rollout, operação e rollback

**Pré-requisitos:** A–E aprovados; manifest/config válidos; release Mithril compatível com Forge; benchmark ponta a ponta disponível; Eregion com circuit breaker testado.

| Etapa | Runtime | Critério de avanço / retorno |
|---|---|---|
| 0 | `off` (default) | Release sem regressão; `doctor --strict` em CI |
| 1 | `observe` em staging, watchpoints explícitos | Sem PII, alarmes de instrumentação, overhead documentado |
| 2 | `observe` em produção limitada | Métricas estáveis; avaliar amostragem dos probes elegíveis |
| 3 | `enforce`, **sem** `recycle_on_verified_state_violation` | Validar findings e false positives antes de enforcement ativo |
| 4 | `enforce` + flag, rollout pequeno + breaker ativo | Monitorar disponibilidade, reciclagens, tempo em open e rejeições |
| 5 | Expansão gradual | Apenas com métricas e runbook aprovado |

### Runbook mínimo

- **Alerta:** breaker abriu; investigar `workload`, reason normalizada, frequência, saúde do pool e deploy recente.
- **Diagnóstico:** usar `durin worker:audit` fora de produção com cenário A→B representativo, sem copiar payload de cliente.
- **Mitigação:** se falso positivo ou instrumentação defeituosa, desativar **somente** o enforcement afetado por configuração controlada e redeploy de workers; registrar risco. **Não** devolver workers já sinalizados ao pool.
- **Falha genuína:** corrigir estado residual no código (escopo, static, singleton), validar auditoria, publicar nova geração, observar recuperação e fechar breaker conforme política.
- **Incidente de indisponibilidade:** seguir política de resposta HTTP/degradação existente, sem habilitar workers suspeitos para restaurar throughput à força.
- **Rollback:** voltar ao último conjunto de releases compatíveis, com `off`/`observe` e configuração validada; evitar rollback que reintroduza cruzamento de estado entre usuários.

---

## 10. Sequenciamento dos PRs e gates

### PR 0 — Baseline e ADR atualizado (sem mudança de comportamento)

**Dono:** MithrilPHP, com coleta coordenada em Forge/Eregion. Inventário, benchmarks originais, versão do Composer, matriz de modos e baseline de performance. **Gate:** relatório reproduzível.

### PR 1 — Hot path otimizado e classificação de evidências

**Dono:** MithrilPHP. Fast path, execução pré-resolvida, sampling de `observe`, preservação de `enforce`, rigor no conceito `verified`, testes U01–U10/U14. **Gate:** não regressão + benchmark comparativo (mesmos cenários e ambiente).

### PR 2 — Anti-storm e readiness

**Dono:** Eregion. `GuardRecycleBreaker`, integração com restart lifecycle, proteção `half_open`, métricas, concorrência e tests E01–E11. **Gate:** suíte Go + race em CI compatível + simulação de violação persistente sem busy-loop.

### PR 3 — Validação e DX

**Dono:** Durin’s Forge + interface de configuração Mithril. `doctor --strict`, warnings por boot, `worker:audit` compatível; testes U11–U13. **Gate:** instalação Composer limpa e suite do Forge.

### PR 4 — Integração, observabilidade e benchmark final

**Dono:** coordenação dos três repos. Integração end-to-end, documentação, rollout, relatórios e gates P/I/O. **Gate:** todos os critérios atendidos ou desvios formalmente aceitos com evidência; nada de declarar “production ready” se breaker, compatibilidade ou métricas falharem.

**Dependências entre PRs:** PR 1 independente da ativação do breaker; **não habilitar enforce com reciclagem em produção antes do PR 2**. PR 3 pode avançar em paralelo com PR 2, desde que use versão de interface estável. Releases em ordem controlada, preservando rollback.

---

## 11. Definição de pronto (Definition of Done)

- [ ] Config `off` continua default e tem fast path medido.
- [ ] `observe` tem custo por probe documentado e amostragem somente em verificações elegíveis.
- [ ] `enforce` não amostra verificação capaz de reciclar e mantém opt-in duplo.
- [ ] `verified` é explicado como violação de contrato, com teste que distingue mera mudança de hash.
- [ ] `scope_cleanup_failure` e reasons das políticas anteriores permanecem prioritários.
- [ ] Eregion contém tempestade de reciclagens **sem reutilizar** worker suspeito.
- [ ] Readiness e recusa HTTP refletem capacidade real; outros workloads não são afetados.
- [ ] Config inválida em HTTP desliga Guard **com warning visível**; CLI strict e audit sinalizam erro.
- [ ] Observabilidade não transporta dados sensíveis nem labels não limitadas.
- [ ] Benchmark vazio + dois cenários HTTP com Eregion publicados, com ambiente e resultados brutos.
- [ ] `composer test` Mithril/Forge, `go test ./...`, integração ponta a ponta e `go test -race` em CI compatível executados.
- [ ] Sem alteração de wire format EREGION/1 ou APIs HTTP; sem `ResourceProbe`/`JobWorker` nesta entrega.
- [ ] Runbook, ADR, rollback e gates de release atualizados.

---

## 12. Não objetivos e próximos incrementos

**Fora desta entrega:** ResourceProbe, `worker:inspect`, auditoria em `JobWorker`, isolamento por processo por usuário, interposição em extensões nativas, heap dump, um SLO-based autopilot, scaling por backlog, API dinâmica de breaker e protocolo de telemetria novo.

**Evoluções possíveis após medir V1.1:**

1. ResourceProbe via **instrumentação voluntária** de recursos, não inventário automático de toda conexão PHP.
2. Auditoria em `JobWorker`, respeitando `ack/retry/reject` e sem duplicar consumo de brokers.
3. Telemetria agregada do WorkerGuard com canal dedicado/versionado somente se houver benefício suficiente para justificar mudança de contrato.
4. Policy packs por tipo de aplicação e replay sintético de cenário A→B em CI.

---

## 13. Prompt de execução recomendado

> Implemente a **SPEC-WG-002 — WorkerGuard V1.1: Performance & Safety Hardening** como referência de implementação. Leia a SPEC-WG-001, o estado atual das três branches e os arquivos de documentação/ADR do WorkerGuard. Preserve `off` default, opt-in de reciclagem, prioridades de `scope_cleanup_failure` e políticas existentes, e o protocolo EREGION/1. Entregue fase A e PR 1 primeiro, com benchmark reproduzível; depois adicione circuit breaker por workload no Eregion, sem jamais reutilizar workers sinalizados. Implemente validação/diagnósticos no Durin e testes de ponta a ponta. Não implemente ResourceProbe, JobWorker ou novas abstrações de workload. Cada fase exige testes, evidências, métricas, limitações e documentação. Não declare sucesso se o benchmark real com Eregion, os testes de concorrência ou os critérios de segurança não forem cumpridos.

---

## 14. Código-base de referência

- MithrilPHP — [`src/Runtime/Worker.php`](https://github.com/EreborCodeForge/mithrilphp/blob/main/src/Runtime/Worker.php)
- MithrilPHP — [`src/Runtime/WorkerMetadata.php`](https://github.com/EreborCodeForge/mithrilphp/blob/main/src/Runtime/WorkerMetadata.php)
- MithrilPHP — [`src/Runtime/Recycling/`](https://github.com/EreborCodeForge/mithrilphp/tree/main/src/Runtime/Recycling)
- Eregion — [`internal/worker/pool.go`](https://github.com/EreborCodeForge/eregion/blob/main/internal/worker/pool.go)
- Eregion — [`internal/protocol/messages.go`](https://github.com/EreborCodeForge/eregion/blob/main/internal/protocol/messages.go)
- Durin’s Forge — [`src/Console/Application.php`](https://github.com/EreborCodeForge/durins-forge/blob/main/src/Console/Application.php)
- Durin’s Forge — [`src/Console/Commands/DoctorCommand.php`](https://github.com/EreborCodeForge/durins-forge/blob/main/src/Console/Commands/DoctorCommand.php)

**Observação de rastreabilidade:** valores de benchmark e quantidades de testes acima foram fornecidos pelo usuário, não reexecutados na criação deste documento. O agente implementador deve reproduzi-los e registrar diferenças antes de otimizar.
