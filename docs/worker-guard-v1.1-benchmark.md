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

## Linux, 2026-11-10

Ubuntu 24.04 no WSL2, kernel 5.15.146, GCC 13.3.0, Go 1.23.4 linux/amd64, PHP 8.5.9 NTS. O app é `bench/http/App.php`, rota `GET /item`. `observe` usa `mode=observe` e `sample_every_n_requests=10`. Cada modo sobe e derruba o próprio Eregion antes do próximo. Comando, a partir do checkout do Eregion:

```text
EREGION_HTTP_BENCH=1 go test ./tests/integration/ -run TestMithrilHTTPBench -count=1 -timeout 25m -v
```

Sequencial, 1 worker, 300 requests, 3 rodadas:

| modo | rps p50 | latência p95 | latência p99 | rodadas, rps |
| --- | ---: | --- | --- | --- |
| off | 1416,8 | 1,028 ms | 1,224 ms | 1417, 1363, 1476 |
| observe | 1386,3 | 1,026 ms | 1,182 ms | 1261, 1386, 1402 |
| representative_off | 893,7 | 1,404 ms | 1,683 ms | 871, 906, 894 |
| representative | 893,4 | 1,420 ms | 1,613 ms | 865, 893, 896 |

Queda mediana de `observe` contra `off`: 2,2%. No modo com `usleep(200)`, a queda mediana é 0,03%. Neste Linux o atraso fica na casa de 1 ms, não no piso de 16 ms do Windows.

Concorrente, 4 workers, 8 clientes, 1600 requests, 3 rodadas. RSS é a soma de `VmRSS` dos processos `eregion-worker`. CPU é a soma do tempo de usuário e sistema desses processos, em percentual de um núcleo:

| modo | rps p50 | p50 | p95 | p99 | RSS KiB | CPU | rodadas, rps |
| --- | ---: | --- | --- | --- | ---: | ---: | --- |
| off | 5247,8 | 1,420 ms | 3,076 ms | 3,802 ms | 131332 | 55,8% | 3840, 5278, 5248 |
| observe | 5125,5 | 1,434 ms | 3,008 ms | 3,679 ms | 130852 | 64,1% | 3912, 5125, 5245 |
| representative_off | 5276,2 | 1,396 ms | 2,406 ms | 3,255 ms | 131096 | 72,5% | 3683, 5276, 5651 |
| representative | 5463,5 | 1,354 ms | 2,282 ms | 2,960 ms | 130936 | 78,5% | 3623, 5464, 5775 |

No JSON leve a queda mediana de throughput é 2,3% e o RSS fica empatado (cerca de 128 MiB nos quatro workers). A CPU sobe de 55,8% para 64,1%, cerca de 15% a mais em relação ao `off`. No modo com atraso, a mediana de `observe` ficou 3,5% acima de `off`, dentro da faixa das rodadas (3683–5651 no `off`). A meta de 5% de throughput não fica certificada: a mediana desta amostra está abaixo de 5%, e a dispersão das rodadas é maior que essa diferença. A amostra do Windows, 8,4%, continua acima de 5%. A meta de 3 µs do hook não foi remedida aqui.

## Hook observe_light, piso de 2026-11-10

Mesmo runner e o mesmo Windows/PHP 8.5.10 da amostra de 8441 ns. Comando: `php bench/worker_guard_bench.php --requests=100000 --rounds=10 --label=hook-opt`. Nenhuma rodada descartada. JSON: `bench/results/hook-orch.json` (só a orquestração) e `bench/results/hook-opt.json` (orquestração mais o atalho de `null` do watchpoint).

O perfil de 20.000 ciclos, antes da mudança, separou o hook de 7634 ns em probes (scoped 518, estático 1825, memória 1269, soma 3612) e o restante, 4022 ns. Dentro desse restante: dois pares de `hrtime` 324 ns, `id()` mais `sampleable()` 698 ns, recriar `$beforeOk` e escrever por nome 1147 ns, `hash('xxh128')` de `null` 413 ns, `getValue` 132 ns. `memory_get_usage` três vezes foi 171 ns; a memória isolada do probe foi 1269 ns, então o custo dela não é a syscall. O guard sem probe nenhum levou 1766 ns. Scoped não foi alterado.

A orquestração trocou o mapa `$beforeOk[$probe->id()]` por um bool por índice e deixou de chamar `accepts()` quando `sample_every_n_requests <= 1` ou o modo é `audit`. Com `n > 1`, estático e scoped continuam em todo ciclo e só o probe `sampleable` é amostrado. `enforce` não passou a amostrar.

| etapa | hook p50 | mínimo | máximo |
| --- | ---: | ---: | ---: |
| antes (faixa publicada) | 8441 | 7336 | 9136 |
| índice inteiro, `hook-orch` | 6286 | 6052 | 7529 |
| mais atalho de `null`, `hook-opt` | 5326 | 5194 | 6123 |

O máximo de `hook-orch` (7529) ficou abaixo do mínimo publicado (7336). O máximo de `hook-opt` (6123) ficou abaixo do mínimo de `hook-orch` (6052). `observe_empty` ficou em 843 ns (771–1005), ao lado dos 810 ns anteriores. Queda de throughput `observe_light` contra `off` nesta amostra: 56,06% (68873 contra 156757 rps). A amostra anterior era 66,38%.

Probes isolados depois do atalho, 20.000 ciclos, fora do `Worker`: scoped 325 ns, estático 896 ns, memória 1160 ns. Soma 2381 ns. O estático de `null` deixa de recalcular `xxh128` em todo request; escalar não nulo continua no salt e no hash. `verified` e `observed` não mudaram.

A meta de 3 µs não foi atingida. O requisito WG11-P02 continua **p50 ≤ 3 µs**; o número abaixo é baseline observado, não um requisito novo. Neste host ele é 5,3 µs (faixa 5,2–6,1 µs, `bench/results/hook-opt.json`). A repetição `php bench/worker_guard_bench.php --requests=100000 --rounds=10 --label=hook-opt-recheck` deu p50 5374 ns (5125–6527, `bench/results/hook-opt-recheck.json`) e fica no mesmo baseline. A soma dos três probes isolados mais cerca de 0,3 µs dos dois pares de `hrtime` é cerca de 2,7 µs: é o menor número compatível com a cobertura atual, e o hook ainda acrescenta cerca de 2,6 µs de laço, `try`/`finally` e ciclo sem finding. Chegar a 3 µs exigiria cortar esse restante sem tirar probe, relógio ou a leitura do watchpoint. Isso não aconteceu nesta amostra, e o requisito não foi alterado por causa disso.

## HTTP pareado no ext4, 2026-11-10

Ubuntu no WSL2, PHP 8.5.9, Go 1.23.4. Os dois repositórios foram copiados para `/home/thales/wg-validate`, sem symlink para `/mnt/c`. Dez pares alternados `off` depois `observe`, cada lado com warmup de 100 requests, 4 workers, 8 clientes e 1000 requests medidos. O processo morre antes do lado seguinte. JSON: `bench/results/http-pairs-linux.json`.

A perda de cada par é `(rps_off - rps_observe) / rps_off`. Média −4,92%. IC 95% com t de Student e 9 graus de liberdade: −14,36% a 4,52%. O limite superior fica em 5% ou abaixo. O intervalo cruza zero.

RSS ficou em torno de 128 MiB nos dois lados. CPU dos workers variou de 52% a 73% de um núcleo, sem um lado sempre mais caro.

## O que não é PASS

- Meta de 3 µs no hook de `observe_light`: o requisito WG11-P02 continua p50 ≤ 3 µs e não foi atingido. Baseline observado: 5,3 µs (faixa 5,2–6,1 µs) em `bench/results/hook-opt.json`. A rechecagem ficou em p50 5374 ns (`bench/results/hook-opt-recheck.json`). A faixa anterior era 7,3–9,1 µs. O baseline não substitui o requisito.
- Meta de 5% no HTTP: o teste pareado em ext4, 10 pares `off`/`observe`, tem IC 95% de −14,4% a 4,5% (`bench/results/http-pairs-linux.json`). O teto de 5% fica abaixo do limite superior. A média é −4,9% e o intervalo inclui zero, então não há um overhead positivo estável. A amostra antiga do Windows, 8,4%, é outro cenário.
- Tag `v0.4.1` do Durin não contém `worker:audit`. `v0.4.2` publica o comando, mas o lock ainda instala `mithrilphp` v3.0.0, que não tem o Guard. `v0.4.3` exige `^3.1` e trava o dist publicado v3.1.1. `v3.1.0` do MithrilPHP não contém este baseline; `v3.1.1` publica a otimização e estas evidências.
