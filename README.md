
```
 __    __     __     ______   __  __     ______     __     __        
/\ "-./  \   /\ \   /\__  _\ /\ \_\ \   /\  == \   /\ \   /\ \       
\ \ \-./\ \  \ \ \  \/_/\ \/ \ \  __ \  \ \  __<   \ \ \  \ \ \____  
 \ \_\ \ \_\  \ \_\    \ \_\  \ \_\ \_\  \ \_\ \_\  \ \_\  \ \_____\ 
  \/_/  \/_/   \/_/     \/_/   \/_/\/_/   \/_/ /_/   \/_/   \/_____/ PHP
```

# MithrilPHP

Bem-vindo ao **MithrilPHP**, um framework PHP forjado para ser robusto como o metal lendário, porém leve e flexível para qualquer batalha de desenvolvimento.

## 🚀 Propósito

O MithrilPHP nasceu da necessidade de uma estrutura que unisse a solidez da **Clean Architecture** (DDD) com a simplicidade de um micro-framework. Nosso objetivo é fornecer uma base inquebrável para suas aplicações, sem o peso desnecessário de frameworks monolíticos.

Ele é projetado para desenvolvedores que valorizam o controle, a performance e a organização de código, permitindo que você construa desde APIs RESTful de alta performance até aplicações full-stack complexas.

## 🌟 A Origem: O Desafio AppMarket

O MithrilPHP não foi criado em um laboratório isolado, mas sim forjado no calor da batalha. Ele nasceu da necessidade real de refatorar o **AppMarket**, uma aplicação legada de gerenciamento de mercado que sofria com código espaguete, falta de padrões e dificuldade de manutenção.

Diante do desafio de modernizar o AppMarket sem reescrevê-lo do zero com frameworks pesados, desenvolvemos uma estrutura própria, focada em resolver problemas reais de engenharia de software: desacoplamento, testabilidade e clareza. O resultado dessa refatoração foi tão robusto e flexível que abstraímos o núcleo (Core) para dar vida ao MithrilPHP.

Hoje, o AppMarket (incluído neste repositório como exemplo) serve como a prova viva do poder do framework: um sistema complexo transformado em uma arquitetura limpa e elegante.

## 💪 Pontos Fortes

- **Leveza Extrema**: Sem dependências ocultas ou "mágicas" desnecessárias. Você tem controle total sobre o ciclo de vida da requisição.
- **Arquitetura Limpa**: Estrutura de pastas nativa orientada a domínios (Domain, Application, Infrastructure, Presentation), facilitando a manutenção e escalabilidade.
- **Forge CLI**: Ferramenta de linha de comando integrada para gerenciar migrações, servir a aplicação e gerar recursos.
- **Injeção de Dependência**: Container de DI simples e poderoso para gerenciamento de serviços.
- **Database Agnostic**: Camada de abstração de banco de dados flexível (MySQL/SQLite) com sistema robusto de migrações.

## 🎨 Engine de Views Prática e Flexível

Um dos maiores diferenciais do MithrilPHP é sua **View Engine Agnóstica**. Entendemos que o frontend evolui rápido, e seu backend não deve prender você a uma única tecnologia.

O MithrilPHP foi desenhado para servir qualquer tipo de frontend com facilidade:

*   **Vue.js**: Integração nativa e fluida (configuração padrão).
*   **React**: Suporte total para renderização de componentes React.
*   **HTML Puro / PHP Tradicional**: Para quem prefere a simplicidade e velocidade do server-side rendering clássico.

Não importa a sua escolha de interface, o MithrilPHP entrega os dados e a estrutura que você precisa, onde você precisa.

## 🔨 Forge CLI: Simplicidade e Poder

Esqueça a complexidade de configurar ferramentas de linha de comando externas. O MithrilPHP vem equipado com o **Forge**, um CLI nativo projetado para eliminar dores de cabeça.

![Forge CLI Preview](docs/images/forge_preview.png)

O Forge oferece controle total sobre o banco de dados e o servidor de desenvolvimento:

*   **Migrações Robustas**: Sistema completo de versionamento de banco de dados.
    *   `php forge migrate`: Executa migrações pendentes.
    *   `php forge migrate:rollback`: Desfaz o último lote de alterações com segurança.
    *   `php forge migrate:fresh`: Reseta o banco completamente (Drop + Migrate) para um estado limpo.
*   **Command Bus Extensível**: A estrutura de comandos (`src/Console/Commands`) é construída para ser facilmente estendida. Você pode criar comandos personalizados para qualquer necessidade da sua aplicação (Crons, tarefas de manutenção, importações) implementando uma simples interface.

## 📦 Zero Dependencies & Native Power

Acreditamos na força da linguagem PHP. Por isso, o núcleo do MithrilPHP evita o inchaço de bibliotecas de terceiros para funcionalidades essenciais:

*   **Environment Nativo**: Parseamento de arquivos `.env` feito internamente, rápido e seguro, sem dependências como `vlucas/phpdotenv`.
*   **Database Wrapper (Sem ORM forçado)**: Utilizamos uma camada fina sobre o PDO que oferece segurança e praticidade sem impor um ORM pesado e lento.
    *   *Nota*: Graças à arquitetura baseada em interfaces, você é livre para integrar o Doctrine, Eloquent ou qualquer outro ORM se o projeto exigir, mas o MithrilPHP já vem pronto para a batalha "out of the box".

## 🛒 Aplicação de Exemplo: AppMarket

Para demonstrar o poder do framework em um cenário real, este repositório inclui o **AppMarket**, um sistema completo de gerenciamento de mercado e controle de estoque.

Este exemplo ilustra como organizar regras de negócio complexas. Embora o domínio seja varejo, a arquitetura (Clean Architecture/DDD) demonstra como o MithrilPHP é agnóstico ao negócio, servindo perfeitamente para qualquer ramo: SaaS, E-commerces, Sistemas Financeiros ou APIs Corporativas.

## 🛠️ Instalação e Configuração

### Pré-requisitos
- PHP 8.3 ou superior
- Composer

### Passos

1. **Instale as dependências:**
   ```bash
   composer install
   ```

2. **Configure o ambiente:**
   Copie o arquivo de exemplo e configure suas variáveis (Banco de dados, etc).
   ```bash
   cp .env.example .env
   ```

3. **Execute as migrações:**
   Prepare seu banco de dados com nosso sistema de migração.
   ```bash
   php forge migrate
   ```

4. **Inicie o servidor:**
   Utilize o Forge para levantar o servidor de desenvolvimento.
   ```bash
   php forge serve
   ```

## 🏗️ Arquitetura e Componentes

### 🔄 Sistema de Migrations
O MithrilPHP utiliza um sistema de migração puro e transparente, armazenando o histórico na tabela `migrations`.

*   **Versionamento**: Cada migração é registrada com um timestamp e um número de lote (batch), permitindo que você saiba exatamente quando e em qual grupo uma alteração foi aplicada.
*   **Segurança**: O `MigrationRunner` garante que, se uma migração falhar, o processo pare imediatamente, prevenindo estados inconsistentes.
*   **Flexibilidade**: Escreva SQL puro ou utilize o wrapper PDO. Você tem controle total sobre os tipos de dados e índices, sem ficar preso às limitações de um Query Builder abstrato.

### 💉 Dependency Injection Container
No coração do framework vive um Container de Injeção de Dependência poderoso, localizado em `src/Core/Container.php`.

*   **Auto-wiring**: O container é capaz de resolver dependências automaticamente via Reflection. Se seu controller precisa de um `UserRepository`, basta tipar no construtor e o container injetará a instância correta.
*   **Ciclo de Vida**:
    *   `bind()`: Registra instâncias transitórias (uma nova a cada chamada).
    *   `singleton()`: Registra instâncias únicas (compartilhadas por toda a requisição).
*   **Desacoplamento**: Facilita a adesão ao princípio de Inversão de Dependência (SOLID), permitindo que você troque implementações (ex: `MySQLRepository` por `MongoRepository`) alterando apenas uma linha na configuração de bindings.

### 📝 Logs e Monitoramento
A visibilidade é crucial. O MithrilPHP implementa uma interface de logging compatível com PSR-3 (`src/Core/Logger/LoggerInterface.php`).

*   **File Logger**: Por padrão, utilizamos o `FileLogger` que escreve logs estruturados em `logs/app.log`.
*   **Níveis de Severidade**: Suporte completo a todos os níveis de log (Emergency, Alert, Critical, Error, Warning, Notice, Info, Debug).
*   **Contexto**: Os logs suportam arrays de contexto, que são serializados em JSON, permitindo armazenar detalhes ricos sobre erros (stack traces, IDs de usuários, dados de request) para facilitar o debug.

## 📂 Estrutura do Projeto

```
src/
├── Application/    # Casos de uso e regras de aplicação
├── Domain/         # Entidades e regras de negócio puras
├── Infrastructure/ # Implementações concretas (BD, Serviços externos)
├── Presentation/   # Controllers e API
└── Core/           # O coração do framework (Router, Container, Kernel)
```

---
*Forged by EreborCodeForgee*
