# Eregion — fonte confiável do binário

**Status:** Draft  
**Audiência:** maintainers do Application Server (Go) e da Forge (MithrilPHP)  
**Objetivo:** definir o que o repositório Eregion deve publicar para que `forge server:install` baixe, verifique e instale o binário com segurança — sem clonar o repo nem exigir toolchain Go.

---

## 1. Princípio

A fonte canônica do binário **não** é o source tree Git.

É um **GitHub Release versionado**, com:

- assets por OS/arch com nomes estáveis;
- checksums (SHA-256) no mesmo release;
- CLI de versão/protocolo parseável.

MithrilPHP / Forge **consome** essa fonte. Eregion **publica** e mantém o contrato.

```text
Tag vX.Y.Z
  ├── eregion-<os>-<arch>[.exe]
  └── checksums.txt (SHA-256)

forge server:install
  → resolve versão pinada
  → baixa asset
  → confere hash
  → grava .mithril/bin/eregion
```

---

## 2. Releases

| Requisito | Detalhe |
|-----------|---------|
| Versionamento | Tags semânticas: `v0.1.0`, `v1.2.3` |
| Imutabilidade | Assets de um tag **não** são reescritos após publicação |
| Canal | GitHub Releases do repo canônico do Eregion (org/repo estáveis) |
| “latest” | Permitido para DX; **produção** usa versão pinada pela Forge |

A Forge não deve depender de `go build` no caminho feliz.

---

## 3. Nomes dos assets (congelados)

Formato:

```text
eregion-<os>-<arch>[.exe]
```

Matriz mínima:

| Asset | Plataforma |
|-------|------------|
| `eregion-linux-amd64` | Linux x86_64 |
| `eregion-linux-arm64` | Linux aarch64 |
| `eregion-darwin-amd64` | macOS Intel |
| `eregion-darwin-arm64` | macOS Apple Silicon |
| `eregion-windows-amd64.exe` | Windows x86_64 |

Regras:

- Nomes **não mudam** entre versões (só o conteúdo do binário).
- Sem espaços; lowercase; hífen como separador.
- Extensão `.exe` **somente** no Windows.
- Binários Linux/macOS com bit executável preservado no artefato (ou a Forge aplica `chmod +x` após download).

---

## 4. Checksums

Todo release deve incluir um arquivo de checksums no mesmo tag, por exemplo:

```text
checksums.txt
```

Formato sugerido (uma linha por asset):

```text
<sha256hex>  eregion-linux-amd64
<sha256hex>  eregion-linux-arm64
<sha256hex>  eregion-darwin-amd64
<sha256hex>  eregion-darwin-arm64
<sha256hex>  eregion-windows-amd64.exe
```

Regras:

- Algoritmo: **SHA-256**.
- Nomes dos arquivos nas linhas = nomes exatos dos assets.
- A Forge **recusa** instalar se o hash não bater ou se o asset não constar na lista.
- Opcional futuro: assinatura GPG/cosign do `checksums.txt` (não bloqueia o MVP).

---

## 5. CLI do binário (contrato mínimo)

O binário publicado deve expor:

| Comando | Uso pela Forge |
|---------|----------------|
| `eregion version` ou `eregion --version` | `server:version` / `server:check` |
| `eregion serve --config=... --manifest=...` | `forge serve` |
| Overrides úteis | `--host`, `--port`, `--workers` (alinhados ao yaml) |

Saída de versão **estável e parseável**, por exemplo:

```text
eregion 0.1.0
protocol eregion/1
```

Campos esperados (texto livre desde que previsível):

- versão do binário;
- **protocol version** (`eregion/1` no v1).

Assim `server:check` pode rejeitar binário incompatível com o worker PHP.

---

## 6. O que a Forge pinará / consumirá

Lado MithrilPHP (quando `server:install` deixar de ser stub):

| Item | Exemplo |
|------|---------|
| Repo | `EreborCodeForge/eregion` (configurável) |
| Versão pinada | `extra.mithril.eregion` em `composer.json` ou `EREGION_VERSION` |
| URL do asset | `https://github.com/<org>/<repo>/releases/download/vX.Y.Z/eregion-<os>-<arch>` |
| Destino local | `.mithril/bin/eregion` (`.exe` no Windows) |
| Overrides | `EREGION_BINARY`, depois `PATH`, depois `.mithril/bin/` |

Ordem de resolução (já alinhada à lib):

1. `EREGION_BINARY`
2. `eregion` no `PATH`
3. `.mithril/bin/eregion`
4. download via `server:install`

---

## 7. Checklist do repo Eregion

Antes de considerar a fonte “confiável” para a Forge:

- [ ] CI gera binaries multi-OS no tag
- [ ] Release sobe automaticamente os assets da matriz §3
- [ ] `checksums.txt` (SHA-256) no mesmo release
- [ ] Nomes de asset estáveis entre versões
- [ ] `eregion version` / `--version` com protocol version
- [ ] `eregion serve --config --manifest` funciona no asset publicado
- [ ] Documentação do release aponta protocol `EREGION/1` (ou superior)

---

## 8. Fora de escopo (não é fonte confiável)

- Clonar o Git e rodar `go build` como caminho padrão
- Versionar o binário dentro do repositório **mithrilphp**
- Distribuir só via Packagist/Composer (ruim para nativos multi-OS)
- “Latest” sem pin em produção
- Reescrever assets de um tag já publicado

---

## 9. Relação com o restante do contrato

- Protocolo worker PHP ↔ Go: spec da ponte Eregion / MithrilPHP
- Config do servidor: `eregion.yaml` (defaults de runtime = Go)
- Boot da app PHP: manifesto `var/runtime/eregion.json` (Forge)

Este documento cobre **somente** a distribuição confiável do binário.

**Build with Mithril. Run in Eregion.**
