# ADR 0002 — WorkerGuard V1.1: fast path, evidência e anti-storm

**Status:** aceito  
**Data:** 2026-11-10  
**Spec:** `docs/SPEC-WG-002-WORKERGUARD-V1.1-PERFORMANCE-SAFETY-HARDENING.md`  
**Complementa:** `docs/adr/0001-worker-guard.md`

## Contexto

O MVP mede o Guard, mas o loop vazio em `observe` custa dezenas de microssegundos por request e o Eregion reinicia sem limite um worker que pede `guard_state_violation` em toda geração. A SPEC-WG-002 pede overhead medido, diagnóstico de configuração e um circuit breaker por workload, sem mudar o EREGION/1.

## Decisões

`off` continua default. O `Worker` lê `enabled()` uma vez no boot. Com o Guard inativo, o request não cria `GuardCycleContext`, não chama probe, reflection, hash nem finding. A `RecyclingPolicy` existente, inclusive `memory_get_usage`, permanece.

`sample_every_n_requests` só pula probe com `sampleable() === true`. Hoje isso é só `memory_trend`, que não recicla. `scoped_container` e `static_watchpoints` rodam em todo ciclo de `observe`, `enforce` e `audit`. `enforce` não amostra o que pode virar `critical`/`verified`. `audit` força a memória a rodar em todo ciclo para não pular a prova.

`verified` é violação local do contrato de reset de um watchpoint escalar `lifetime=request`. Não é prova de contaminação entre usuários. Mudança só de tipo em valor não escalar fica `warning`/`observed` e não recicla. `lifetime=worker` e estático fora da lista continuam fora da verificação. A chave do hash é efêmera, gerada no boot, e não entra em log, JSON ou métrica. HMAC-SHA256 foi medido mais lento que o keyed `xxh128` e não entra no hot path.

A reciclagem continua com `scope_cleanup_failure` na frente e com a razão já decidida pela política. O Guard só preenche `guard_state_violation` quando a política devolve keep, o modo é `enforce` e `recycle_on_verified_state_violation` está ligado. Erro de probe não recicla e não derruba a resposta.

Config HTTP inválida ou `mode=audit` cai em `off` e emite um aviso uma vez por processo: `WorkerGuard invalid configuration; guard disabled for this worker. Run durin doctor --strict`. O texto não repete o valor rejeitado. `durin doctor` trata isso como warning. `durin doctor --strict` sai diferente de zero. `worker:audit` continua 0, 1 e 2.

No Eregion, `GuardRecycleBreaker` é por pool/workload e só conta `guard_state_violation`. Worker sinalizado não volta para `idle`. Com o breaker `open`, restart por essa razão e scale-up de slot novo ficam bloqueados. Restart por `max_requests`, `memory_limit`, crash ou shutdown de um slot saudável segue o fluxo anterior. `half_open` admite um processo de prova, identificado por slot e geração. O pool arma essa tentativa quando o cooldown termina; shutdown cancela a espera. Ele só volta a `closed` depois de `recovery_successful_requests` respostas sem violação e de `recovery_min_uptime` nesse mesmo processo. PID iniciado não é recuperação. `enabled: false` restaura o restart ilimitado anterior.

O Eregion não recebe findings do PHP. As métricas novas usam workload configurado e reason `guard_state_violation` ou `other`. `eregion_worker_recycles_total` continua e se sobrepõe ao contador do breaker para essa reason.

## Consequências

Não há `ResourceProbe`, telemetria nova no fio, nem tag publicada do Mithril. O Forge de desenvolvimento carrega `../mithrilphp/src` nos testes. Uma instalação Composer limpa só passa a ver esta V1.1 depois de uma tag compatível com `mithrilphp:^3.0`. `go test -race` não rodou nesta máquina: `CGO_ENABLED=0` e não há `gcc`.
