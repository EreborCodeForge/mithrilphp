# ADR 0001 — WorkerGuard: limites de detecção e opt-in

**Status:** aceito  
**Data:** 2026-11-10  
**Spec:** `docs/SPEC-WORKERGUARD-MITHRIL-EREGION-DURIN.md` (SPEC-WG-001)

## Baseline medido nesta data

Antes de qualquer código do Guard:

- MithrilPHP `composer test`: 133 testes, 381 asserções, OK.
- Eregion `go test ./...`: OK, incluindo `internal/worker` (onde vive `TestConsumerScaleDownForceKill`) e `tests/integration`. A falha citada na spec em 2026-09-29 não se reproduziu nesta execução e não foi alterada.

Contratos que o Guard reutiliza, sem mudar o protocolo EREGION/1:

- `Worker` faz boot uma vez e, por request, `beginScope` → `handle` → `endScope` no `finally`. Se `beginScope` lança, `endScope` não roda.
- `WorkerMetadata.recycle` / `recycleReason` já seguem no `ResponseMapper` como `recycle` e `recycle_reason`.
- `RecyclingPolicy` decide `max_requests`, `memory_limit`, `max_uptime` e `max_jobs`. A primeira política que pede reciclagem vence.
- Eregion `Pool.Release` já trata `Recycle=true` como reciclagem planejada e chama `IncRecycle(reason)`.

## Decisão

O MithrilPHP é o dono do WorkerGuard. O modo default é `off`: nenhum probe, nenhuma mudança de resposta e nenhuma reciclagem nova. `observe` registra findings e não recicla. `enforce` só pede reciclagem com a flag `recycle_on_verified_state_violation` e apenas para finding `critical`/`verified` de estado. `audit` existe na CLI, não no worker HTTP: config `audit` no processo HTTP é inválida e cai em `off`.

`endScope` continua limpando só o registry `scoped` e chamando `cleanup()` quando existe. Isso não prova que estáticos, extensões ou memória nativa foram limpos. O Guard não promete isolamento.

## O que a detecção cobre

- Registry `scoped` vazio depois de `endScope`, via contagem read-only. Não detecta referência externa que ainda aponte para o objeto.
- Propriedades estáticas listadas explicitamente com `lifetime=request`. A comparação guarda um hash salgado do scalar, ou só o tipo quando o valor não é scalar. O valor não entra em finding, log, métrica nem JSON.
- Tendência de `memory_get_usage` / `memory_get_peak_usage` como `warning`/`suspected`. Não recicla. `recycle_on_suspected_memory_growth=true` invalida a config inteira.

Não há contador de conexão, stream ou transação no Mithril. Não existe `ResourceProbe` nesta entrega.

## Precedência

`scope_cleanup_failure` continua na frente. Se a `RecyclingPolicy` já decidiu reciclar, a razão dela permanece no `WorkerMetadata`. O finding do Guard fica no relatório. O Guard só preenche `recycle_reason` quando a política devolve keep. Razões novas permitidas no fio: `guard_state_violation` e `guard_resource_violation`. `guard_probe_error` não recicla.

A ordem da spec que coloca o Guard acima de `max_requests` é classificação de relatório, não troca da razão já decidida.

## Tempestade de reciclagem

Não há supressor que ignore `Recycle=true`. Isso deixaria um worker contaminado em serviço. O controle é o opt-in de `enforce` e o `MinReady`/restart que o Eregion já tem.

## Cardinalidade

Labels de métrica no Eregion passam por allowlist. Razão fora da lista vira `worker_requested` no log e na métrica. O envelope não ganha campos.
