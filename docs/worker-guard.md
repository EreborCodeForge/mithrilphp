# WorkerGuard

O WorkerGuard observa workers PHP que ficam vivos entre requests. O modo default é `off`: nenhum probe e nenhuma mudança de resposta. Ele não isola PHP arbitrário e não substitui o `endScope` nem o Eregion.

## O que entra no ciclo

Depois do `boot`, cada request continua `beginScope` → `handle` → `endScope`. Com o Guard ligado, `before` roda depois do `beginScope` e `after` roda depois do `endScope`, antes da única resposta. Falha de probe vira `guard_probe_error` e não derruba a resposta em `observe` ou `enforce`. Falha de `endScope` continua `scope_cleanup_failure` e não é substituída.

`config/worker_guard.php` é lido pelo worker HTTP. Arquivo ausente, inválido ou com `mode=audit` deixa o processo em `off`. `audit` é só da CLI.

## O que os probes veem

- `scoped_container`: a contagem do registry `scoped` depois do `endScope`. Zero não prova que outra variável ainda segura o objeto.
- `static_watchpoints`: só propriedades listadas com `lifetime=request`. O probe guarda um hash com chave efêmera do scalar, ou apenas o tipo quando o valor não é scalar. O valor e a chave não vão para o relatório. Divergência de scalar contra o baseline é `critical` / `verified`: contrato local de reset, não prova de que um usuário contaminou o outro. Divergência só de tipo não escalar é `warning` / `observed` e não recicla. `lifetime=worker` e qualquer estático fora da lista não são violação.
- `memory_trend`: amostra `memory_get_usage(false)`, `memory_get_usage(true)` e `memory_get_peak_usage(true)` a cada `sample_every_n_requests`. Esse probe é o único sampleable. Crescimento sustentado é `warning` / `suspected` e não recicla. A chave `recycle_on_suspected_memory_growth` tem de ser `false`; caso contrário a config inteira é rejeitada. `enforce` não usa o sampler nos probes que podem reciclar. `audit` roda a memória em todo ciclo.

Não há `ResourceProbe`. O Mithril não conta conexões, streams ou transações.

## Reciclagem

O Guard só pede `guard_state_violation` quando a `RecyclingPolicy` ainda não pediu reciclagem, o modo é `enforce` e a flag `recycle_on_verified_state_violation` está ligada. `verified` aqui é a quebra local do contrato de reset de um watchpoint escalar `lifetime=request`. Uma mudança de hash permitida (`lifetime=worker`) ou só de tipo não escalar não é `verified` e não recicla. `guard_resource_violation` existe na allowlist para um probe futuro e não é emitido agora. `guard_probe_error` não recicla.

O Eregion trata o sinal como reciclagem planejada e não devolve esse worker para `idle`. Se a mesma reason se repetir além de `worker_guard_recycle_safety.max_recycles` na janela, o breaker do workload abre e segura novos boots causados pelo Guard. O processo de prova em `half_open` é o par slot + geração: a geração sozinha se repete entre slots. Quando o cooldown acaba, o pool dispara uma tentativa sozinho, cancelada no shutdown, sem loop imediato de restart. Workers que saem por `max_requests`, `memory_limit`, crash ou shutdown continuam podendo ser repostos. Sem worker elegível, a recusa HTTP já existente responde; a readiness mostra a capacidade real. No label da métrica, razão fora da lista vira `worker_requested` no contador antigo e `other` no contador do breaker. O envelope EREGION/1 não ganhou campos.

`eregion_worker_recycles_total` e `eregion_worker_guard_recycle_events_total` contam o mesmo recycle de `guard_state_violation`. O segundo existe para o breaker e não substitui o primeiro.

## Configuração inválida

Arquivo ausente deixa o worker em `off`, sem aviso. Arquivo inválido ou `mode=audit` também fica em `off`, com um aviso no stderr uma vez por processo, sem repetir o valor rejeitado. `durin doctor` mostra warning. `durin doctor --strict` falha. `durin worker:audit` continua com saída 0 (limpo), 1 (finding) e 2 (configuração ou execução inválida).

## Contadores

`ProbeWorkerGuard::stats()` devolve `probe_ok`, `probe_error`, `hook_duration_ns`, `inspections` e `findings_by_code`. `hook_duration_ns` é o agregado monotônico do hook. O mapa `probe_duration_ns` permanece vazio no request: cronometrar cada probe dentro do hook custava mais do que o probe nesta máquina. O custo separado de cada probe está no loop isolado de `bench/worker_guard_bench.php`. Os códigos de finding são a allowlist já existente. O relatório guarda um exemplar por diagnóstico; `findings_by_code` continua somando cada ocorrência. Em `off` esses contadores não são atualizados por request. O log de finding, quando ligado pelo worker HTTP, só sai para finding real, com os campos da spec e com teto por janela. Não leva valor, hash, salt, URL com query nem corpo.

## Benchmark

Runner novo: `php bench/worker_guard_bench.php --requests=100000 --rounds=10 --label=baseline`.

Ele usa uma fila com cursor. O script antigo `php tests/Benchmark/worker_guard_compare.php 2000 5` usa `array_shift` e não é comparável em valor absoluto com o runner novo. Os dois deixam o Eregion de fora.

HTTP com Eregion: `EREGION_HTTP_BENCH=1 go test ./tests/integration/ -run TestMithrilHTTPBench -count=1 -v` a partir do checkout do Eregion, com o Mithril ao lado. Sem a variável o teste fica `N/A` e não entra na suíte comum.

Números, hardware e o que não atingiu a meta estão em `docs/worker-guard-v1.1-benchmark.md`.

## Rollout

0. `off`, que já é o default. `durin doctor --strict` no CI.
1. `observe` em staging, só com watchpoints explícitos.
2. `observe` em produção limitada, com a amostragem só no probe de memória.
3. `enforce` ainda sem `recycle_on_verified_state_violation`.
4. `enforce` com a flag, em pouco tráfego, com o breaker ligado.
5. Expansão só com as métricas e este runbook.

## Runbook

- Breaker aberto: olhar workload, reason normalizada, saúde do pool e o deploy recente. Não recolocar no pool um worker que já pediu `guard_state_violation`.
- Diagnóstico: `durin worker:audit` fora de produção, com um cenário A→B, sem copiar payload de cliente.
- Falso positivo: desligar o enforcement na configuração e subir workers novos. Os processos já sinalizados continuam fora.
- Falha genuína: corrigir o estado residual, repetir a auditoria e deixar o breaker fechar pela política de recuperação.
- Indisponibilidade: seguir o 503 e a readiness atuais. Não marcar o pool como pronto para esconder slot vazio.
- Rollback: voltar aos releases compatíveis com `off` ou `observe`. Não reintroduzir worker já sinalizado.

## Pendências

- `ResourceProbe`, `durin worker:inspect` e hook de `JobWorker` ficaram de fora.
- O requisito WG11-P02 continua p50 ≤ 3 µs. O baseline observado do hook `observe_light` é 5,3 µs e não substitui esse requisito. Números e metodologia: `docs/worker-guard-v1.1-benchmark.md`.
- `go test -race ./...` não rodou nesta máquina Windows: o Go pediu cgo e não há `gcc` no PATH.
