# LexIA

Plataforma SaaS jurídica. Laravel 12 + Inertia 3 + React 19 + Tailwind 4,
PostgreSQL com pgvector, PHP 8.4+.

## Convenções

- **Identificadores em inglês.** Classes, métodos, colunas e props em inglês.
  O português vive nos labels dos enums, nas mensagens de validação, nas URLs
  e na UI. Ex.: `UserRole::AccountAdmin->label() === 'Admin da Conta'`.
- **Casos de uso são Actions** (`lorisleiva/laravel-actions`). `handle()` não
  conhece HTTP; `asController()` é a única casca de adaptação. Uma Action por
  caso de uso, apontada diretamente pela rota.
- **DDD por pasta**, não por autoload: `app/Domain/{Accounts,Users,Shared}`,
  tudo sob o PSR-4 `App\`. Migrations e factories seguem em `database/`.
- **Complexidade ciclomática ≤ 10** em `app/`. Ver a skill abaixo.
- **Tela nova passa pela skill `layout`**: tokens de cor, Tailwind 4,
  `AppLayout`, tabelas e o dropdown de ações de linha.

## Comandos

```bash
docker compose up -d                 # Postgres + pgvector na porta 5442
php artisan migrate:fresh --seed     # senha de todos os usuários: password
composer dev                         # serve + queue + pail + vite
php artisan test                     # PHPUnit contra o banco lexia_testing
php artisan lexia:embed-procedural-classes  # vetores do catálogo (após mexer nas descrições)
./vendor/bin/pint                    # formatação
composer analyse                     # phpstan nível 6, sem supressões
npm run types:check                  # tsc --noEmit
php .claude/skills/cyclomatic-complexity/scripts/analyze.php app
```

## Multitenancy — leia antes de mexer

A conta é a fronteira. `TenantContext` (singleton) guarda a conta corrente;
`BindTenantContext` a define **depois** da autenticação. `AccountScope` filtra
todo model que usa `BelongsToAccount`, e `VisibleAccountScope` limita a própria
tabela `accounts`.

Duas consequências que já morderam:

1. **Sem usuário autenticado o escopo não se aplica.** Filas e comandos veem
   todas as contas. Use `TenantContext::actingAs($accountId, fn () => ...)` ou
   `Model::acrossAllAccounts()`, que é explícito e visível em review.
2. **Route-model binding roda antes do middleware de tenant.** Um recurso de
   outra conta é resolvido e barrado pela Policy (403, não 404). As Policies
   são a defesa real para modelos vinculados por rota — nunca confie só no
   escopo ao escrever uma nova.

## Papéis

`PlatformAdmin` > `AccountAdmin` > `Admin` > `Lawyer`. Os três últimos são
internos à conta; `PlatformAdmin` é a equipe LexIA e o único papel que
atravessa a fronteira do tenant — administra todas as contas e os usuários
delas. A regra transversal é que **ninguém gerencia um par ou um superior**
(`UserRole::outranks()` é estrito), o que impede dois admins de se trancarem
mutuamente; `User::canManage()` abre exceção apenas para o `PlatformAdmin`,
que por definição age fora da própria conta.

O papel é a única fonte de autoridade: não existe mais a coluna
`users.platform_admin`, e `User::isPlatformAdmin()` deriva de
`UserRole::PlatformAdmin`.

## A conta da plataforma

A LexIA tem sua própria conta (`AccountType::Platform`, id fixo em
`Account::PLATFORM_ID`), criada por **migration** e não por seeder: produção
também precisa dela. É onde vivem os `PlatformAdmin`.

Nem o tipo nem o papel são oferecidos em formulário — cadastro público e
edição de conta trabalham com `AccountType::customerCases()`, e a conta da
plataforma não pode ser desativada nem excluída (desativá-la reprovaria
`canAccessPlatform()` de toda a equipe). Testes que contam contas precisam
descontá-la.

## O design system

A interface é **shadcn/ui** no estilo *new-york*: os componentes não vêm de um
pacote, são código do projeto em `resources/js/components/ui/` — copiá-los é o
ponto, já que a edição local é o mecanismo de customização. O `components.json`
está configurado, então `npx shadcn@latest add <componente>` cai no lugar certo
e encontra o `cn` em `@/lib/utils`.

A paleta não mudou: continua croma 0 — preto, branco e cinzas —, com cor
reservada a erro e sucesso. O que mudou é o vocabulário. As escalas `ink-*` e
`brand-*` deram lugar aos **tokens semânticos** do shadcn em
`resources/css/app.css`: `:root` e `.dark` guardam os valores, `@theme inline`
os expõe como `bg-background`, `text-muted-foreground`, `border-border`. Um
componente nunca nomeia um cinza, só o papel que ele cumpre — é o que permite
trocar o tema (ou herdar uma cor de marca futura) num arquivo só. `--success`
é acréscimo nosso ao conjunto padrão, para o par que a UI precisa significar.

Três consequências práticas:

1. **O menu lateral é o `Sidebar` do shadcn.** `SidebarProvider` guarda o
   estado num cookie (`sidebar_state`), `ctrl/cmd+B` recolhe, e abaixo de
   `md` ele vira uma gaveta (`Sheet`). Recolhido, vira trilha de ícones com
   tooltip — daí todo item de navegação precisar de um ícone.
2. **O tema é uma classe na raiz.** `useAppearance` (claro/escuro/sistema)
   grava em `localStorage`, e um script inline no `app.blade.php` reaplica a
   classe *antes* da primeira pintura; sem ele a tela pisca em claro a cada
   navegação dura.
3. **`Field` enxerta `id` e `aria-invalid` no controle que envolve.** É o que
   liga rótulo e erro a um `Select` do Radix, que é um botão e não um
   `<select>`. Esse Select (`SelectInput`) fala `onValueChange`, não
   `onChange`, e reserva a string vazia para "nada escolhido" — o item que
   limpa um filtro viaja com um valor sentinela e volta como `''`.

As ações de uma linha de tabela — visualizar, editar, excluir — ficam todas
dentro de `RowActions` (`resources/js/components/row-actions.tsx`), o botão de
três pontos: a coluna guarda um botão só, e uma operação nova entra na lista
em vez de alargar a tabela. Quem não pode executar não vê o item, e a lista já
chega filtrada pelo `can` que a Action publicou — a Policy segue sendo a defesa
de verdade.

As abas continuam sendo links, não o primitivo do Radix: quem guarda o estado
é a URL (ver abaixo).

## O painel

`AppLayout` é uma grade de altura fixa: o `SidebarProvider` recebe `h-screen`
com `overflow-hidden`, de modo que a página em si nunca rola. Só a coluna do
meio tem `overflow-y-auto` — o menu da esquerda e o cabeçalho ficam parados,
independentemente do conteúdo. Filtros de listagem não vão no corpo: a layout
recebe `filters` e desenha um painel lateral à direita, aberto por um botão no
cabeçalho que mostra quantos filtros estão ativos. Como toda navegação de
filtro usa `preserveState`, o painel continua aberto enquanto os resultados
mudam.

A conta é a raiz de tudo o que é cadastro: `/contas` (só `PlatformAdmin`),
`/contas/nova` e `/contas/{account}`, que tem duas abas — **Dados gerais**
(`accounts/show`) e **Usuários** (`accounts/users`). As abas são URLs de
verdade, não estado local, para que uma listagem filtrada sobreviva a um
reload. Os usuários vivem sob `/contas/{account}/usuarios/...`: não existe mais
listagem de usuários fora de uma conta.

Consequência para as Actions de usuário: a URL carrega **dois** modelos, e o
route-model binding resolve ambos antes do middleware de tenant. A conta da
URL, e não a do ator, é a que vale — daí `UserIndexQuery` filtrar por
`account_id` explicitamente, já que para a equipe LexIA o escopo está aberto.
`ActsWithinAccount::withinRoutedAccount()` é o guarda que recusa uma conta
alheia e um usuário que não pertence à conta da URL; ele só funciona se o
`asController()` tipar `Account`, que é o que dispara o binding.

## Autenticação

Fortify com telas Inertia próprias, registradas em `FortifyServiceProvider`.
As regras de negócio do login (usuário desabilitado, conta suspensa) vivem em
`Fortify::authenticateUsing()`, para que um login recusado nunca chegue a criar
sessão. O `/register` do Fortify está desligado: o cadastro público é
`RegisterAccountWithOwner`, que cria conta e primeiro usuário numa transação.

## Armadilhas de versão

- O projeto está em **Vite 7**. `laravel-vite-plugin` v3 e
  `@vitejs/plugin-react` v6 exigem Vite 8 — manter em `^2` e `^5`.
- `laravel/ai` exige PHP ^8.3; daí o `^8.4` no composer.
- Busca vetorial é **nativa do core do Laravel 12**
  (`$table->vector()`, `whereVectorSimilarTo()`). Não existe
  `pgvector/pgvector-php`; não instale nada para isso. O cast `AsVector` que a
  documentação do SDK mostra **não existe** no framework 12.69.2 — o nosso está
  em `App\Domain\Shared\Casts\AsVector`.
- A doc oficial do Laravel AI mostra o Ollama como `driver => openai-compatible`;
  o pacote v0.11.2 tem `OllamaProvider` nativo (`driver => ollama`), que é o que
  este projeto usa — ele fala `/api/chat` e `/api/embed` de verdade.
- **Nunca mande `think: false`** a um modelo que raciocina: o `gpt-oss:20b`
  respondia com conteúdo vazio e a linha qwen3 pensa por padrão. O padrão
  (thinking ligado) já entrega JSON limpo, porque o Ollama separa o raciocínio
  em `message.thinking`. O `qwen2.5:7b` de hoje não raciocina — a armadilha está
  dormente, não removida.
- `sebastian/complexity` está vendorizado mas **quebra em enums** — por isso a
  skill de complexidade usa `nikic/php-parser` direto.

## Os agentes

`laravel/ai` falando com um Ollama local: `qwen2.5:7b` para texto,
`nomic-embed-text` (768 dimensões) para embeddings. `config/ai.php` declara um
provider só, de propósito — a narrativa de um caso não sai da infraestrutura do
escritório para ser processada.

Nem o modelo nem o tamanho do contexto pertencem a um agente. Nenhum deles carrega
`#[Model]` — o nome do modelo vive em `OLLAMA_TEXT_MODEL` e o SDK resolve cada
agente por `defaultTextModel()` —, e o `num_ctx` sai de `ai.context_window`
(`AI_CONTEXT_WINDOW`, 24576) através do trait `UsesConfiguredContextWindow`. É
o que torna a troca de modelo uma linha de `.env`: o número descreve o tamanho
dos prompts que escrevemos, não a janela do modelo da vez. Sem ele o Ollama
truncaria em silêncio um prompt grande — o maior prompt de classe tem 10.946
tokens medidos pelo `prompt_eval_count` do próprio Ollama.

O conhecimento tem **dois regimes de recuperação**, cada um onde ganha. O markdown
de `app/Rag/knowledge/` vai inteiro pelo `KnowledgeBase`: para escolher entre 24
opções que cabem no prompt, um corte por similaridade só conseguiria remover a
opção certa, e um desempate do tipo "não confunda X com Y" é irrecuperável por
similaridade, porque o trecho relevante fala da opção *errada*.

O **catálogo de classes**, esse é vetorizado — `procedural_classes.embedding`,
`vector(768)` do pgvector. Ali o corpus não cabe: só as candidatas da maior área
passam de 30 KB depois do enriquecimento. `ProceduralClassRankingQuery` ordena as
candidatas pela proximidade com o relato e `DescribedCandidateBudget` decide
quantas cabem descritas; as demais chegam ao prompt só com nome e código.

A distinção que sustenta o desenho: **o vetor nunca tira uma candidata do `enum`**.
Ele escolhe onde gastar a janela, não quais respostas são possíveis — é a objeção
ao top-k, evitada por construção. Os dois READMEs em `app/Ai` e `app/Rag`
detalham o resto.

O enquadramento de um caso são **dois** agentes em série, e a ordem é imposta, não
escolhida. `PracticeAreaClassificationAgent` recebe os fatos e devolve a área de
atuação; `ProceduralClassSelectionAgent` recebe a área já decidida e escolhe entre
as classes processuais vinculadas a ela. As opções chegam **injetadas** nas
instruções e também como `enum()` no `schema()` — o Ollama converte isso em
gramática, então nem uma área nem uma classe inventada é algo que o modelo consiga
emitir. E as classes candidatas só existem depois que a área é conhecida: daí não
caber numa chamada só.

A classe é escolhida pelo **código do CNJ**, um inteiro, e não pelo slug: slug de
classe processual **não é único** (559 distintos em 615 linhas). O uuid nunca entra
no prompt — é gerado na migration de carga e difere entre bancos —, só sai no
`toArray()`, para ser gravado.

A descrição de cada classe de ajuizamento é **operacional**, não doutrinária: o que
se pede ao juiz, o pressuposto, o prazo próprio quando é ele que distingue a classe,
e os instrumentos. Junto vai `legal_bases`, os artigos que a peça cita. É o que
separa um par como `[172] Embargos à Execução` (15 dias da citação, art. 915 do CPC,
sem garantia) de `[1118] Embargos à Execução Fiscal` (30 dias, art. 16 da LEF,
depois de garantido o juízo). Tudo isso é redação nossa: uma ressincronização com a
TPU do CNJ não a devolve — ver `database/data/README.md`.

Só entram na lista as classes de ajuizamento (`is_filing_class`): um relato sem
processo em curso é uma inicial, então recurso, incidente e cumprimento de sentença
não podem ser a resposta. `processual-geral` é a única área sem nenhuma, e cai de
volta para todas as suas — lista de candidatas vazia seria `enum` vazio, que é
gramática inválida.

Quem encadeia é `ClassifyLegalCase`, que devolve `LegalCaseClassification` com as
duas entidades e **uma justificativa para cada**.

Mais dois agentes são **acrescentados à cadeia, não encadeados nela**, e não
dependem nem dela nem um do outro — leem os mesmos fatos e respondem outras
perguntas. `DefendantExtractionAgent`, exposto por `ExtractLegalCaseDefendant`,
devolve os dados do réu nas doze chaves `defendant_*` de `legal_cases`, já no
formato de `DefendantData` — o objeto que `UpdateLegalCaseDefendant` recebe.
`RequirementExtractionAgent`, exposto por `ExtractLegalCaseRequirements`, devolve
o que o cliente pede ao juízo como `RequirementListData` — o objeto que
`SaveLegalCaseRequirements` recebe. O que os pôs ali foi o chamador, porque o
preenchimento inteligente é um gesto só e o advogado não deve esperar três vezes
pelo mesmo relato. As Actions seguem chamáveis sozinhas, e é assim que a etapa 2
ou a etapa 4 de uma peça já salva deve pedir a sugestão: uma inferência, e não
quatro.

A diferença de natureza. Os dois primeiros **escolhem** uma linha de catálogo; o
do réu **copia**, então os doze campos são `required()` e `nullable()` ao mesmo
tempo — a gramática obriga as chaves a existirem e faz do `null` a resposta
legítima para o que o relato não diz. Um réu é descrito, não cadastrado. O dos
pedidos faz as duas coisas: **quais** pedidos existem é leitura, a **frase** de
cada um é composição. Ele não escreve os pedidos de praxe — citação, provas,
honorários —, que a tela já oferece num clique em `SUGGESTED_REQUIREMENTS`;
escrevê-los aqui entregaria duas cópias de cada um.

**A cifra de um pedido tem guarda estrutural, e é o único lugar do projeto onde
um prompt não bastou.** O `qwen2.5:7b` não soma — a instrução segura isso —, mas
*compõe*: para um caso sem cifra de dano moral no relato ele devolveu R$ 5.000,00
numa rodada e R$ 12.000,00 noutra. Por isso `RequirementListData::fromAgent()`
recebe o relato junto da resposta e recusa toda cifra que os fatos não escrevam.
A guarda é generosa (qualquer número do texto autoriza) e protege a coluna, não a
prosa. Detalhes e o custo conhecido em `app/Ai/README.md`.

Como não há dependência, a falha de um deles não derruba o resto:
`ClassifyLegalCase` reporta e devolve `null` naquela chave, e o enquadramento —
que custou minutos — sobrevive. No payload, `null` é a extração que falhou; doze
campos nulos dentro do objeto são o relato que não identifica ninguém, e a lista
vazia é o relato que não pede nada.

`POST /pecas/classificar` é a única rota das quatro Actions de agente: ela aponta
para `ClassifyLegalCase`, e as outras três não têm `asController()` enquanto
nada apontar para elas. O preço da rota é a latência de **quatro** `Timeout(180)`
em série, com o navegador esperando — dívida conhecida, documentada no
`asController()`, e o lugar de trocá-la por uma fila.

Testes de agente ficam em `tests/Agents`, no grupo `agents`, **fora** do
`php artisan test` padrão porque exigem o Ollama de pé e gastam segundos de
inferência. O grupo é o que os habilita — `--testsuite=Agents` sozinho não
encontra nada, porque a exclusão do grupo continua valendo:

```bash
composer test:agents
```

## Ainda não implementado

O módulo de Jurisprudência: ingestão, chunking e busca vetorial sobre o corpus.
O pgvector já está de pé e em uso no catálogo de classes, então o que falta é a
tabela do corpus, o pipeline de ingestão e o `SimilaritySearch` no agente. Lá o
índice ANN passa a valer — no catálogo, com 615 linhas, o scan exato é mais
rápido do que a perda de recall compensaria.
