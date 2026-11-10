# WorkerGuard V1.1 — benchmark

Medido em 2026-11-10. Host: Windows 10 AMD64, Intel Xeon E5-2680 v4 @ 2.40GHz. PHP 8.5.10, CLI, OPcache carregado com `opcache.enable_cli=0`, JIT `disable`, `memory_limit=128M`. Go 1.23.4 windows/amd64 no Eregion. Nenhuma rodada foi descartada. Meta de 2%, 3 µs e 5% é alvo de engenharia. Este arquivo não marca essas metas como atingidas quando a amostra não sustenta o número.

Os JSON brutos estão em `bench/results/historical-compare.json`, `bench/results/baseline.json` e `bench/results/optimized.json`.

## Comandos

Loop no mesmo processo, fila com cursor, sem Eregion:

```text
php tests/Benchmark/worker_guard_compare.php 2000 5
php bench/worker_guard_bench.php --requests=100000 --rounds=10 --label=baseline
php bench/worker_guard_bench.php --requests=100000 --rounds=10 --label=optimized
```

O primeiro usa `InMemoryBridge` e `array_shift`. O segundo e o terceiro usam cursor. Os rps absolutos dos dois runners não se comparam. O que se compara é a diferença entre modos dentro do mesmo runner.

HTTP pelo Eregion, a partir do checkout do Eregion, com o Mithril ao lado:

```text
EREGION_HTTP_BENCH=1 go test ./tests/integration/ -run TestMithrilHTTPBench -count=1 -timeout 180s -v
```

Sem a variável o teste é `N/A` e não entra em `go test ./...`.

## Histórico do script antigo

`php tests/Benchmark/worker_guard_compare.php 2000 5`, 5 rodadas de 2.000 requests. Mediana: `off` 125494 rps, `observe` 55749 rps, queda de 55,58%. O último hook de `observe` foi 12421300 ns em 2000 requests, cerca de 6,2 µs. O relato anterior da spec, na mesma classe de máquina, era cerca de 100 mil contra 46 mil rps e cerca de 8 µs. A diferença fica no script e na variação da máquina. Não é o número do runner novo.

## Baseline, antes da V1.1

`bench/results/baseline.json`, gerado em 2026-11-10T19:06:05Z. 100.000 requests, 10 rodadas, ordem embaralhada com `mt_srand(1000+round)`.

| modo | rps p50 | hook ns/request p50 |
| --- | ---: | ---: |
| none | 155234 | 0 |
| off | 159282 | 0 |
| observe_empty | 91193 | 1494 |
| observe_light | 52874 | 7850 |
| observe_sampled | 55934 | 6807 |
| enforce_clean | 62016 | 5452 |

`off` contra `none`: queda de −2,61% (`off` um pouco mais rápido). `observe_light` contra `off`: queda de 66,8%. CPU mediana por request, via `getrusage`: `off` 6250 ns, `none` 6406 ns, `observe_light` 18906 ns. Hash isolado, 100.000 iterações: keyed `xxh128` 423 ns, HMAC-SHA256 2343 ns. HMAC fica de fora do request.

O caminho `off` já era `NullWorkerGuard`. A perda grande é `observe` contra `off`, não `off` contra a ausência de Guard.

## Depois do fast path

`bench/results/optimized.json`, gerado em 2026-11-10T19:34:18Z, mesmo comando e o mesmo protocolo. Este é o número publicado. Rodadas anteriores no mesmo dia mediram um bug (ciclo sem probe marcado como incompleto) e um cronômetro por probe dentro do hook. As duas foram descartadas. O hook agora só tem o par de relógio do `before`/`after`. O custo de cada probe está no loop isolado, fora do `Worker`.

| modo | rps p50 | hook ns/request p50 | CPU ns/request p50 |
| --- | ---: | ---: | ---: |
| none | 142719 | 0 | 6875 |
| off | 161149 | 0 | 6250 |
| observe_empty | 92648 | 1531 | 10781 |
| observe_light | 51025 | 8890 | 19531 |
| observe_sampled | 57263 | 6993 | 17500 |
| enforce_clean | 59982 | 6281 | 16250 |

`off` contra `none` nesta amostra: queda de −12,91% (`off` mais rápido). O p50 absoluto de `off` passou de 159282 para 161149, dentro da faixa do baseline (mínimo 124908, máximo 174669). O lado `none` caiu de 155234 para 142719, então o percentual emparelhado não é uma garantia de 2%. Nesta amostra o caminho `off` não ficou mais lento que `none` e o p50 absoluto não saiu da faixa já vista.

`observe_light` contra `off`: queda de 68,34%. Hook p50 de 8890 ns, cerca de 8,9 µs. O baseline era 7850 ns. A meta de 3 µs não foi atingida. O fast path não reduziu o custo do conjunto scoped + estático + memória.

Loop isolado, 20.000 ciclos de `before`+`after`, fora do `Worker`:

| probe | ns por ciclo |
| --- | ---: |
| scoped_container | 324 |
| static_watchpoints | 1444 |
| memory_trend | 1020 |

A soma isolada é cerca de 2,8 µs. O hook de `observe_light` é 8,9 µs. O resto é o ciclo do Guard em volta dos probes, não o hash. Keyed `xxh128` nesta rodada: 302 ns. HMAC-SHA256: 1931 ns. O request continua no keyed `xxh128` com a chave efêmera prefixada. `hash_init` foi experimentado e não entrou: não batia o `hash()` curto e o HMAC é mais lento.

`observe_empty` ficou em 1531 ns, ao lado dos 1494 ns do baseline. Esse é o custo do modo `observe` sem probe.

## HTTP com Eregion

Um worker, 300 requests sequenciais por rodada, 3 rodadas, um warmup fora da conta. Rota `GET /item`, container com singleton e scoped, middleware local no fixture `bench/http/App.php`. A latência é o relógio do cliente Go. A CPU do processo PHP não foi separada do cliente: fica `N/A` neste cenário.

Rodada publicada, 2026-11-10T19:36:00Z:

| modo | rps p50 | latência p95 | latência p99 | rodadas, rps |
| --- | ---: | ---: | --- | --- |
| off | 1754,4 | 1,0025 ms | 1,0043 ms | 1424, 1754, 1917 |
| observe | 1626,0 | 1,0027 ms | 1,1276 ms | 1626, 1610, 1704 |
| representative_off | 64,7 | 16,239 ms | 16,540 ms | 64,68, 64,65, 64,96 |
| representative | 64,8 | 16,234 ms | 16,652 ms | 64,94, 64,85, 64,67 |

Queda de throughput `observe` contra `off`: 7,3%. Uma rodada anterior, sem o par com atraso, deu 1936,7 contra 1896,0 rps, queda de 2,1%. A meta de 5% no JSON leve não se sustenta entre as duas amostras. Não está atingida.

O modo representativo usa `usleep(200)`. Neste Windows o piso ficou em cerca de 16 ms, não em 200 µs. `representative` e `representative_off` empatam em cerca de 65 rps. Isso mede o relógio do sistema, não o Guard. Não serve como prova da meta de 5%.

Saturação não é este bench sequencial. `TestQueueFull` já cobre um worker, fila de capacidade 1 e atraso de 2 s, e exige ao menos um HTTP 503. A série de violação é `TestWGStorm`: duas gerações com `guard_state_violation`, a terceira não abre geração nova, o breaker fica `open` e a métrica não contém o segredo sintético.

## Correção cirúrgica, 2026-11-10

Mesmo runner e o mesmo host. Comando: `php bench/worker_guard_bench.php --requests=100000 --rounds=10 --label=corrected`. JSON: `bench/results/corrected.json`, `generated_at` 2026-11-10T21:04:19Z.

| modo | rps p50 | hook ns/request p50 |
| --- | ---: | ---: |
| none | 151368 | 0 |
| off | 151409 | 0 |
| observe_empty | 112288 | 810 |
| observe_light | 50906 | 8441 |
| observe_sampled | 64190 | 5991 |
| enforce_clean | 63763 | 5513 |

`off` contra `none`: queda de −0,03%. `observe_light` contra `off`: queda de 66,38%. Hook p50 de `observe_light`: 8441 ns (faixa das 10 rodadas: 7336–9136). O número anterior publicado era 8890 ns e 68,34%. A diferença cabe na faixa desta rodada. A meta de 3 µs não foi atingida.

`observe_empty` caiu de 1531 ns para 810 ns (máximo desta rodada: 1009). Esse é o efeito de não ordenar nem sanitizar a lista quando o ciclo não tem finding. O restante do hook leve continua nos probes. Loop isolado desta rodada, 20.000 ciclos: scoped 392 ns, estático 1809 ns, memória 2095 ns. Soma cerca de 4,3 µs, contra 8,4 µs do hook. `try/catch` vazio mediu cerca de 30 ns. `hrtime` em par, cerca de 150–300 ns. O hash keyed `xxh128` desta rodada: 342 ns. Reflexão `getValue` ficou na casa de 100–270 ns, e a leitura direta do estático só uns 50 ns abaixo: não entrou mudança de acesso. `array_slice` contra um anel de quatro amostras economizaria cerca de 90 ns, dentro do ruído, e não entrou.

HTTP com Eregion, mesmo comando `EREGION_HTTP_BENCH=1 go test ./tests/integration/ -run TestMithrilHTTPBench -count=1 -timeout 180s -v`:

| modo | rps p50 | latência p95 | rodadas, rps |
| --- | ---: | --- | --- |
| off | 1721,7 | 1,0019 ms | 1651, 1722, 1834 |
| observe | 1577,3 | 1,2047 ms | 1565, 1701, 1577 |
| representative_off | 65,0 | 16,309 ms | 65,12, 64,96, 64,98 |
| representative | 64,9 | 16,199 ms | 65,10, 64,93, 64,86 |

Queda de throughput `observe` contra `off`: 8,4%. A rodada publicada anterior foi 7,3%. A meta de 5% não está atingida. O modo representativo continua preso no piso de cerca de 16 ms do `usleep` neste Windows.

## O que não é PASS

- Meta de 3 µs no hook de `observe_light`: 8,9 µs na rodada anterior e 8,4 µs nesta (faixa 7,3–9,1 µs).
- Meta de 5% no HTTP JSON: 7,3% na rodada publicada, 2,1% numa anterior e 8,4% nesta.
- `go test -race ./...`: não executado. Com `CGO_ENABLED=1` o Go responde que não há `gcc` no PATH. Isso não conta como PASS.
- Instalação Composer limpa do Forge: o `composer.json` continua em `mithrilphp:^3.0` e não há tag nova. A suíte local carrega `../mithrilphp/src`.
