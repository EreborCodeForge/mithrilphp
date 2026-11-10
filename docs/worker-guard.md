# WorkerGuard

O WorkerGuard observa workers PHP que ficam vivos entre requests. O modo default é `off`: nenhum probe e nenhuma mudança de resposta. Ele não isola PHP arbitrário e não substitui o `endScope` nem o Eregion.

## O que entra no ciclo

Depois do `boot`, cada request continua `beginScope` → `handle` → `endScope`. Com o Guard ligado, `before` roda depois do `beginScope` e `after` roda depois do `endScope`, antes da única resposta. Falha de probe vira `guard_probe_error` e não derruba a resposta em `observe` ou `enforce`. Falha de `endScope` continua `scope_cleanup_failure` e não é substituída.

`config/worker_guard.php` é lido pelo worker HTTP. Arquivo ausente, inválido ou com `mode=audit` deixa o processo em `off`. `audit` é só da CLI.

## O que os probes veem

- `scoped_container`: a contagem do registry `scoped` depois do `endScope`. Zero não prova que outra variável ainda segura o objeto.
- `static_watchpoints`: só propriedades listadas com `lifetime=request`. O probe guarda um hash salgado do scalar, ou apenas o tipo quando o valor não é scalar. O valor não vai para o relatório. `lifetime=worker` e qualquer estático fora da lista não são violação.
- `memory_trend`: amostra `memory_get_usage(false)`, `memory_get_usage(true)` e `memory_get_peak_usage(true)`. Crescimento sustentado é `warning` / `suspected` e não recicla. A chave `recycle_on_suspected_memory_growth` tem de ser `false`; caso contrário a config inteira é rejeitada.

Não há `ResourceProbe`. O Mithril não conta conexões, streams ou transações.

## Reciclagem

O Guard só pede `guard_state_violation` quando a `RecyclingPolicy` ainda não pediu reciclagem, o modo é `enforce` e a flag `recycle_on_verified_state_violation` está ligada. `guard_resource_violation` existe na allowlist para um probe futuro e não é emitido agora. `guard_probe_error` não recicla.

O Eregion trata isso como reciclagem planejada. No label da métrica, razão fora da lista vira `worker_requested`. O envelope EREGION/1 não ganhou campos.

## Contadores

`ProbeWorkerGuard::stats()` devolve três números de cardinalidade fixa: `probe_ok`, `probe_error` e `hook_duration_ns`. Eles não entram no corpo HTTP.

## Benchmark

Script: `php tests/Benchmark/worker_guard_compare.php 2000 5`.

Mede o `Worker` com `InMemoryBridge` no mesmo processo. Não passa pelo Eregion e não inclui rede. App mínima: liga um estático e o `endScope` limpa. `observe` usa os três probes, com memória amostrada a cada 10 requests.

Rodada de 2026-11-10, PHP 8.5.10, Windows AMD64, 5 rodadas de 2000 requests:

| modo | rps mediano |
| --- | --- |
| off | 100593 |
| observe | 45712 |

A queda mediana foi cerca de 55%. O hook de `observe` ficou em torno de 7 a 11 µs por request (`hook_duration_ns` / 2000). O alvo de 5% da spec não foi atingido neste loop vazio: o request sem Guard dura cerca de 10 µs, então o probe aparece inteiro na conta. As rodadas também variaram (off foi de cerca de 58 mil a 106 mil rps). Não há número de produção com Eregion.

## Pendências

- `ResourceProbe`, `durin worker:inspect` e hook de `JobWorker` ficaram de fora.
- O pacote publicado do Forge ainda depende de `mithrilphp` ^3.0. A suíte local do Forge carrega `../mithrilphp` quando esse diretório existe. Sem essa árvore, a CLI nova não resolve as classes até um release do Mithril.
- `go test -race ./...` não rodou nesta máquina: o detector pede cgo e não há `gcc` no PATH. `go test ./...` passou, inclusive `tests/integration`.
