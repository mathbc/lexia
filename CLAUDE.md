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
launchctl setenv OLLAMA_NUM_PARALLEL 4      # sem isto o daemon atende uma por vez
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
  este projeto usa para embeddings — ele fala `/api/chat` e `/api/embed` de verdade.
- **Nunca mande `think: false`** a um modelo que raciocina: o `gpt-oss:20b`
  respondia com conteúdo vazio e a linha qwen3 pensa por padrão. O padrão
  (thinking ligado) já entrega JSON limpo, porque o Ollama separa o raciocínio
  em `message.thinking`. A armadilha está **viva**, e no pior arranjo possível:
  o modelo de texto configurado é exatamente aquele em que ela foi medida.
- `sebastian/complexity` está vendorizado mas **quebra em enums** — por isso a
  skill de complexidade usa `nikic/php-parser` direto.

## Os agentes

`laravel/ai` com **dois providers**, e a divisão responde a uma pergunta só: quem precisa
da internet? São **oito agentes**; sete rodam no Ollama com `gpt-oss:20b`, e o oitavo é
`PleadingDraftingAgent`, que redige a minuta — ver "A minuta", abaixo. A minuta tem ainda
um segundo agente, `PleadingGroundsReinforcementAgent`, que reforça o DO DIREITO e segue o
provider do de redação. Os embeddings do catálogo nunca saíram da máquina:
`nomic-embed-text`, 768 dimensões.

O **Gemini** responde por **um**: `LegalThesisResearchAgent`, que pesquisa nos portais
oficiais. Ele não tem opção local, e a falha não seria graciosa —
`OllamaGateway::mapTools()` lança `RuntimeException` no primeiro `ProviderTool` que vê, de
modo que apontá-lo para o daemon não degrada a pesquisa, apaga-a (ver "A pesquisa de
teses", abaixo). O transcritor que lê a ficha dele **é** local: transcrever pede um
schema, e schema o Ollama impõe como gramática.

`PracticeAreaClassificationAgent` chegou a apontar para a nuvem, trocando cota por
latência já que a área abre a cadeia mais longa de `POST /pecas/classificar`, e voltou. A
consequência de fronteira que essa ida trazia junto era grande: o relato inteiro do
cliente saía do escritório no enquadramento, sem o estreitamento que
`LegalCaseDossier::forResearch()` faz na pesquisa. Hoje o relato só sai na pesquisa, e ali
já sai cortado.

`AI_PROVIDER` é `ollama`; quem aponta para a nuvem é o `#[Provider('gemini')]` de um
agente só, e mandar qualquer outro para lá é trocar o atributo pela linha comentada logo
acima. O preço da volta está no atributo vizinho: **todo agente carrega
`#[Timeout(360)]`**, o dobro do que bastava quando um flash respondia de um datacenter —
um timeout calibrado para a nuvem transforma uma inferência local que funciona numa
exceção. Os embeddings nunca saíram: as 615 classes já estão vetorizadas com o nomic,
vetor de um modelo não se compara com vetor de outro, e mover custaria uma reembutida
inteira para comprar nada.

Consequência operacional: **o Ollama é dependência de desenvolvimento inteira** —
`ollama pull gpt-oss:20b` e `ollama pull nomic-embed-text` antes de começar. Sem o
daemon de pé nada infere, e a degradação não é uniforme: o texto falha alto, e
`ProceduralClassRankingQuery` degrada em **silêncio** para a ordem do pivot, com as
classificações piorando sem erro nenhum.

O bloco `gemini` do `config/ai.php` já está ativo — a pesquisa de teses depende dele —,
então mandar **todo** o texto para a nuvem são dois gestos: pôr `AI_PROVIDER=gemini` no
`.env` e trocar o `#[Provider('ollama')]` dos sete agentes ainda locais pela linha
comentada logo acima de cada um, mais `config:clear`. A chave `models` no bloco não é enfeite: sem
ela o `GeminiProvider` cai num default que muda com a versão do pacote.

Nem o modelo nem as opções de runtime pertencem a um agente. Nenhum deles carrega
`#[Model]` — o nome do modelo vive em `OLLAMA_TEXT_MODEL` e o SDK resolve cada agente por
`defaultTextModel()` —, e as três grafias do Ollama saem de `config/ai.php` pelo trait
`ConfiguresOllamaRuntime`. Fora do Ollama ele devolve `[]`, então é documentação no dia
em que um agente apontar para a nuvem.

- **`num_ctx`** (`ai.context_window`, 24576) é a janela que o prompt pode encher. Sem ele
  o daemon truncaria em silêncio um prompt grande, e o maior prompt de classe tem 10.946
  tokens medidos pelo `prompt_eval_count` do próprio Ollama. É **uniforme de propósito**:
  o Ollama chaveia o runner carregado pelo `num_ctx`, então um valor por agente forçaria
  recarga do modelo entre um e outro.
- **`keep_alive`** (`ai.runtime.keep_alive`, 30m) é quanto tempo o modelo fica residente.
  O default do daemon é cinco minutos, e toda pausa maior cobra da requisição seguinte a
  recarga de 12,8 GB.
- **`think`** (`ai.runtime.reasoning_effort`, `low`) é a maior alavanca de performance do
  projeto. Medido com o prompt real do agente de pedidos: o padrão gastou 42.239
  caracteres de raciocínio em 156 s e devolveu **lista vazia**, e `low` respondeu em 5,6 s
  com três pedidos; noutra amostra o padrão levou 29 s e devolveu quatro. Mais raciocínio
  comprava variância, não qualidade. **Não confundir com a armadilha do `think: false`**,
  que é outra coisa e continua valendo. Quem compõe prosa — `FactsRefinementAgent` e
  `PleadingDraftingAgent` — sobrescreve `reasoningEffort()` e devolve null.

E há uma quarta grafia que **não** é do projeto: `OLLAMA_NUM_PARALLEL`, variável do
daemon. Sem ela o Ollama atende uma requisição por vez, e o `Concurrency::run` de
`ClassifyLegalCase` não paraleliza nada — três requisições simultâneas foram medidas
terminando em degraus de 0,75 s. Com 48 GB e um modelo de 12,8 GB, quatro slots cabem, e
cada agente ainda ganha o próprio cache de prefixo. É parte do setup, como os `ollama
pull`.

Sobre o cache de prefixo: o Ollama reaproveita o KV de um prefixo idêntico, e isso foi
medido valendo **44×** (3,12 s → 0,07 s num prompt de 4.780 tokens). Uma variável no topo
do prompt anula tudo o que vem depois dela, e por isso `ProceduralClassSelectionAgent`
coloca a área e as candidatas no **fim** de `instructions()`. `PleadingDraftingAgent` é a
exceção deliberada, e o docblock dele diz por quê.

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
instruções e também como `enum()` no `schema()`, e o provider impõe o schema —
gramática no Ollama, `response_json_schema` na nuvem —, então nem uma área nem uma
classe inventada é algo que o modelo consiga emitir. E as classes candidatas só existem depois que a área é conhecida: daí não
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
pelo mesmo relato. E como não dependem de nada, eles não esperam a vez: as duas
extrações e o enquadramento são as **três tasks** de um `Concurrency::run` dentro
de `ClassifyLegalCase`, começando juntas. As Actions seguem chamáveis sozinhas, e
é assim que a etapa 2 ou a etapa 4 de uma peça já salva deve pedir a sugestão:
uma inferência, e não cinco.

Houve uma quinta etapa aqui, e ela saiu: `ResearchLegalCaseTheses` — a dupla da seção "A
pesquisa de teses" — **lê a peça, e não os fatos**, então não podia entrar no bloco
concorrente e ficava em série depois dele. Duas coisas a tiraram. É a única inferência que
sai da máquina e abre páginas antes de responder, então anulava o ganho do paralelismo: o
advogado esperava por ela antes de ver o primeiro campo. E uma peça não salva não tem onde
guardar o que ela acha — as teses voltavam no JSON, atravessavam o `sessionStorage` e
morriam com a aba. Hoje ela roda ao abrir a etapa 6, sobre uma peça gravada, e
`ClassifyLegalCase::pleading()` deixou de existir junto. O teste
`the_classification_never_researches_and_never_leaves_the_machine` é o que impede a volta.

A diferença de natureza. Os dois primeiros **escolhem** uma linha de catálogo; o
do réu **copia**, então os doze campos são `required()` e `nullable()` ao mesmo
tempo — a gramática obriga as chaves a existirem e faz do `null` a resposta
legítima para o que o relato não diz. Um réu é descrito, não cadastrado. O dos
pedidos faz as duas coisas: **quais** pedidos existem é leitura, a **frase** de
cada um é composição. Ele não escreve os pedidos de praxe — citação, provas,
honorários —, que a tela já oferece num clique em `SUGGESTED_REQUIREMENTS`;
escrevê-los aqui entregaria duas cópias de cada um.

**A cifra de um pedido tem guarda estrutural, e é o único lugar do projeto onde
um prompt não bastou.** Medido no `qwen2.5:7b`: ele não soma — a instrução segura
isso —, mas *compõe*: para um caso sem cifra de dano moral no relato devolveu
R$ 5.000,00 numa rodada e R$ 12.000,00 noutra. Por isso `RequirementListData::fromAgent()`
recebe o relato junto da resposta e recusa toda cifra que os fatos não escrevam.
A guarda é generosa (qualquer número do texto autoriza) e protege a coluna, não a
prosa. Detalhes e o custo conhecido em `app/Ai/README.md`.

Como não há dependência, a falha de um deles não derruba o resto:
`ClassifyLegalCase` reporta e devolve `null` naquela chave, e o enquadramento —
que custou minutos — sobrevive. No payload, `null` é a extração que falhou; doze
campos nulos dentro do objeto são o relato que não identifica ninguém, e a lista
vazia é o relato que não pede nada.

`POST /pecas/classificar` é a única rota das Actions de agente: ela aponta para
`ClassifyLegalCase`, e as demais não têm `asController()` enquanto nada apontar para elas.
O preço da rota continua sendo latência, com o navegador esperando, mas encolheu duas
vezes. As quatro etapas estão num `Concurrency::run` — três tasks, porque área e classe
são uma cadeia —, então a espera é a mais longa delas e não o total; e a saída da pesquisa
tirou da conta a única que saía da máquina. Sobram quatro inferências locais.
**A dívida da fila continua de pé**, e agora por um motivo menor do que era.

Duas consequências operacionais. O driver vive em `CONCURRENCY_DRIVER`
(`config/concurrency.php`): em `process` — o default, e o único que serve a um request web,
já que o `fork` recusa rodar fora do console — cada task é um `artisan` novo, com o retorno
atravessando `serialize()`; `sync` devolve o comportamento em série sem tocar em código, e é
a saída se a cota do provedor reclamar das requisições simultâneas. E a suíte padrão **tem**
de rodar em `sync`, fixado no `phpunit.xml`: um dublê registrado no container do processo de
teste não cruza para um processo filho, então em `process` os mocks seriam ignorados e os
testes iriam ao provedor de verdade. `tests/Agents/LegalCaseClassificationTest` sobrescreve
isso de propósito — é o único lugar que exercita o bloco como produção o roda, e custa
minutos de máquina, mais a cota do Gemini e a rede da última etapa, podendo ficar vermelho
porque um portal caiu.

Um agente continua fora da cadeia e não é chamado por ela. `FactsRefinementAgent`,
exposto por `RefineLegalCaseFacts`, reescreve o relato do cliente como a narrativa de
fatos de uma inicial: registro formal, terceira pessoa, ordem cronológica — e, quando o
relato narra uma perda que não se repõe (morte na família, o animal da casa, o bem de
valor afetivo insubstituível, a humilhação, o tratamento negado), o peso que ela tem. Em
cobrança, atraso de entrega e contrato entre empresas, nenhum: as duas listas estão nas
instruções para que a decisão seja tomada e não sentida, e `impact_basis` é onde o agente
declara qual fato do relato autorizou o destaque. É o único que recebe um `LegalCase` em
vez de uma string — `LegalCaseDossier` monta a peça em volta (área, classe, cliente, réu
e pedidos já registrados), porque é ela que diz como chamar as partes e o que a narrativa
precisa sustentar.

É também o primeiro cuja resposta é **prosa**, e isso muda o que se pode garantir. A
cifra inventada do agente de pedidos reaparece aqui como "totalizando R$ 24.000,00", e
`RefinedFactsData` a **relata** em `unsupportedAmounts` em vez de apagá-la: uma coluna
pode ficar em branco, uma frase não. No prompt, o que segurou a conta foi proibir os
conectivos ("totalizando", "no total de", "perfazendo") e não a operação. O que o
`qwen2.5:7b` não segurava — a oração encaixada que carrega o fato central — está
medido contra o `qwen3.8:27b` no `app/Ai/README.md`; o `gpt-oss:20b` de hoje não foi
medido contra essa tabela, e é por isso que a saída deste agente é uma minuta para o
advogado aceitar, nunca um campo que se preenche sozinho.

Testes de agente ficam em `tests/Agents`, no grupo `agents`, **fora** do
`php artisan test` padrão porque gastam inferência de verdade. Para quase todos a conta
é só o tempo da máquina — Ollama de pé com `gpt-oss:20b` e `nomic-embed-text` baixados —,
e com os agentes locais e `#[Timeout(360)]` esse tempo é real: conte minutos, não segundos.
`LegalCaseClassificationTest` voltou a custar só o daemon: as quatro etapas são locais, e
a pesquisa saiu da cadeia. Com `think: low` esse tempo caiu de minutos para dezenas de
segundos.
`LegalThesisResearchTest` é o extremo e custa **cota do Gemini** mais rede: ele pesquisa
nos portais de verdade, leva minutos e pode ficar vermelho porque o STJ está fora do ar,
e não porque o prompt regrediu. Tudo o que nele não depende do modelo — a guarda de
domínio, os tetos, os ids de correlação e o achatamento — está repetido de forma
determinística e sem rede em `tests/Unit/Domain/LegalResearchDataTest`, que roda na
suíte padrão e é o arquivo em que confiar quando o outro estiver vermelho por fora. O grupo é o que os habilita — `--testsuite=Agents` sozinho não
encontra nada, porque a exclusão do grupo continua valendo:

```bash
composer test:agents
```

## A revisão forense

A sexta etapa da peça (`LegalCaseStep::Review`) tem duas entidades filhas de
`LegalCase`, ambas 1-N: `LegalThesis` é o que a peça argumenta — nome, tipo
(`LegalThesisType`), descrição, impacto e a fundamentação em `legal_bases`, um
jsonb de `{type, reference, source}` —, e `LegalPrecedent` é o julgado que
sustenta a tese, com a ementa, a citação ABNT e a aderência ao caso.

O precedente aponta para a tese por **FK** (`legal_thesis_id`, nullable), e não
por pivot, porque a linha não guarda a súmula e sim o *achado*: a Súmula 393
existe uma vez no direito brasileiro e uma vez por peça nesta tabela, já que a
aderência e o que ela fundamenta mudam de caso para caso.

`adherence` e `legal_bases` são nulos quando ninguém mediu e ninguém escreveu —
a mesma distinção que `requirements.amount` faz. Zero diria que foi medido e
julgado irrelevante, que é outra afirmação.

**A armadilha está na gravação, e é por ela que existe uma Action só.** Um
precedente carrega a chave da tese que fundamenta, e essa chave pode nomear uma
tese criada no mesmo request — cujo id real não existe até ser gravada, porque
o palpite do navegador é descartado e o `HasUuids` cunha o seu. Então
`SaveLegalCaseForensicReview` grava as teses primeiro e a gravação devolve um
**mapa do id postado para o id persistido**; a FK do precedente sai desse mapa e
nunca do payload. Duas Actions não teriam onde guardá-lo.

O mapa é também a defesa de tenant, de graça: ele só contém as teses que aquela
gravação escreveu, então a tese de outra conta — que o banco aceitaria, já que
uma foreign key confere existência e não propriedade —, a da peça irmã e a que o
advogado acabou de remover aterram todas em `null`. Note que o `nullOnDelete` da
FK quase nunca dispara: `LegalThesis` é soft-deleted, o `delete()` só escreve
`deleted_at` e a constraint não é consultada. Quem desliga é o mapa; a constraint
é a rede do apagamento duro. `DeleteLegalThesis` desliga à mão pelo mesmo motivo.

Uma linha de `saveTheses()` merece a própria frase: o mapa é chaveado em
`$thesis->id ?? $row->id`. Sem o fallback, duas teses novas colidem na string
vazia, a segunda sobrescreve a primeira no mapa, e a primeira é apagada pelo
`whereNotIn` microssegundos depois de criada.

As Actions de cadastro por linha (`CreateLegalThesis`, `UpdateLegalPrecedent`,
`DeleteLegalThesis`…) existem para a edição por linha que virá, e não têm
`asController()` enquanto nada apontar para elas — o agente de revisão forense
já chegou, e escreve a etapa inteira de uma vez pela dupla da pesquisa. A gravação
também chegou: quem a dispara é o "Concluir e gerar minuta", que desde a chegada da
etapa 7 mora nela e não na 6, por `FinalizeLegalCase` — ver "A minuta", no fim deste
arquivo. As de precedente recebem a
tese como **model e não como id**, que é a mesma regra noutra forma: um id
postado seria um buraco que nenhum teste da classe enxergaria.

## A pesquisa de teses

A segunda metade da etapa 6, e um dos dois lugares do projeto que **saem da máquina** — o
outro é a pesquisa de jurisprudência da etapa 7, que é este mesmo desenho contra outro
portal. Um agente
de pesquisa que não consegue abrir o `stj.jus.br` é um modelo recitando súmula de memória,
que é exatamente o que o prompt inteiro existe para impedir — e buscar e ler página são
ferramentas do lado do provedor, que o `OllamaProvider` recusa antes de montar requisição.
O que viaja é estreitado para compensar: `LegalCaseDossier::forResearch()` corta o cliente
e o réu inteiros. O relato vai, porque não se pesquisa tese sem os fatos que a levantam;
os nomes não vão.

São **dois** agentes, e não um, porque no Gemini schema e busca não cabem no mesmo pedido.
Pedir `response_json_schema` desliga o grounding **em silêncio**: volta 200, o JSON é bem
formado, e o modelo não pesquisou nada — na primeira rodada sob schema o agente acertou a
Súmula 430 e citou a capa do STJ como onde a leu, porque não leu nada. Então
`LegalThesisResearchAgent` busca sem schema, onde comprovadamente funciona, e devolve uma
**ficha rotulada**; `ForensicReviewTranscriptionAgent` só transcreve a ficha para a
estrutura aninhada. `ResearchLegalCaseTheses` chama os dois em série. O custo é o inverso
do usual: o que a ficha não escrever, o transcritor não inventa — e também não recupera.

Note que o par está dividido entre os dois providers: só o pesquisador é do Gemini. O
transcritor não pede rede, pede schema, e schema o Ollama impõe como gramática — então ele
é local como os outros seis, e a ficha é a única coisa que atravessa a fronteira.

Três armadilhas medidas, todas do Gemini e todas silenciosas:

1. **O `->allow([...])` das ferramentas de web é descartado.**
   `GeminiProvider::webSearchToolOptions()` é um `return []` literal, e não há escape
   hatch. A lista de portais oficiais não é imposta pela rede — quem a impõe é
   `OfficialLegalSources::covers()`, na volta, dentro de `LegalResearchData`. Citação sem
   fonte oficial é **removida e relatada**, nunca apagada em silêncio: um advogado diante
   de uma tese sem fundamentação precisa distinguir "não achou nada" de "a guarda recusou".
2. **`maxItems` empilhado estoura o schema.** Teto em `theses` mais teto numa lista
   aninhada dentro dela devolve 400 antes de gerar um token. Só o externo fica na
   gramática; os outros dois tetos vivem em `LegalResearchData`.
3. **`#[MaxSteps]` não compra pesquisa.** Ferramenta de provedor roda dentro de um pedido
   só; subir o teto reenvia o pedido inteiro. Uma chamada direta devolve a ficha em ~43s,
   e a mesma sob `#[MaxSteps(16)]` passou de 1000s e morreu em timeout.

O schema é **aninhado** — cada tese carrega seus precedentes — porque modelo não cunha uuid
de correlação de forma confiável, e o aninhamento torna o vínculo estrutural. Quem achata
em duas listas e cunha o uuid em PHP é `ForensicReviewData::fromAgent()`, que é o par de
`crypto.randomUUID()` no navegador.

### Quem dispara a pesquisa, e exatamente uma vez

Não é mais o `ClassifyLegalCase`: é **abrir a etapa 6**, por
`ResearchLegalCaseForensicReview` (`POST /pecas/{id}/revisao-forense/pesquisar`). A troca
resolveu duas coisas de uma vez — a espera saiu do preenchimento inteligente, e o
resultado passou a ter onde ser gravado, porque ali a peça já tem chave primária. A Action
pesquisa, chama `SaveLegalCaseForensicReview` (o mapa de ids, o diff, o `advanceTo`) e
grava o envelope. A inferência fica **fora** da transação: são minutos de rede, e
segurar uma linha travada por eles seria um lock que ninguém quis.

**O gatilho é `legal_cases.research_findings` ser nulo, e nunca a lista de teses estar
vazia.** É a distinção que sustenta o desenho: uma rodada que abriu os portais e nada
confirmou é resposta legítima e cara que grava zero teses, então um gatilho pela lista
dispararia de novo a cada visita à etapa, a cada troca de aba e a cada reload — gastando
cota toda vez e, pior, substituindo em silêncio o que o advogado já curou, porque a
gravação reconcilia por diff. Uma segunda rodada é o botão "Pesquisar novamente".

Essa coluna (jsonb) guarda o que não tem tabela: a questão pesquisada, os portais
abertos, o pendente e as citações que a guarda recusou — mais o `researched_at`, que não
pode ser o `updated_at` porque toda etapa do assistente toca a peça. Sem ela o painel
`Findings` morreria com a aba, e é justamente ele que separa "não achou nada" de "a guarda
recusou o que achou".

Consequência a montante: **a etapa 1 precisa ter sido salva antes de qualquer navegação**,
senão não há peça para a pesquisa gravar em cima. A trilha do assistente já travava as
outras etapas numa peça nova; o que faltava era o `?etapa`, que abria a etapa 6 de uma
peça parada na 2 — `ShowLegalCaseForm::initialStep()` agora limita pela marca d'água.

A dívida da fila continua de pé aqui, e agora só aqui: são dois `Timeout(360)` em série, e
o primeiro é a inferência mais lenta do projeto. A tela que consome isso é
`ForensicReviewFields`, com o `AnalysisDialog` durante a espera.

E há um teto que não é do SDK: o **`max_execution_time` do PHP**. Um `php.ini` de fábrica
traz 30, e a requisição morre em 32 s dentro do cURL do Guzzle —
`Maximum execution time of 30+2 seconds exceeded (terminated)`. O `+2` é o timeout duro,
que dispara porque o normal não consegue interromper um `curl_exec()` bloqueado: não é
exceção, é o processo abatido, sem gravar nada e sem nada a capturar. Subir o `#[Timeout]`
do agente não move isso um segundo — são coisas independentes, e vence o interpretador.
Quem levanta é o middleware `AllowLongInference` (`ai.request_time_limit`, 900 s, o pior
caso destes dois agentes em série), pelo alias `inference` nas **cinco** rotas que
esperam por uma inferência: classificar, pesquisar as teses, pesquisar a jurisprudência,
concluir e gerar a minuta. Ele só
levanta — zero é ilimitado, que é o que a CLI e o `artisan serve` entregam, e escrever um
número ali construiria a parede em vez de derrubá-la. Num servidor de verdade o corte
volta de fora (`fastcgi_read_timeout`, `request_terminate_timeout`), onde nenhum
`set_time_limit()` alcança, e aí a saída é mesmo a fila.

## A análise de jurisprudência

A sétima etapa, e a segunda que abre **pesquisando**. A etapa 6 procura *teses* — o que a
peça argumenta — nos portais oficiais; esta procura *julgados* no LexML: o acórdão de um
caso parecido, que se cita para mostrar como aquele tribunal já resolveu a questão. No
sistema a jurisprudência se chama `CourtDecision`, e a distinção com `LegalPrecedent` é o
que justifica as duas tabelas — um precedente é uma **afirmação sobre esta peça** (pende
de uma tese, carrega aderência e fundamentação), um julgado é o **documento**, transcrito
do registro, sem nenhuma pontuação ao lado. A leitura é do advogado, e é por isso que a
linha não tem `legal_thesis_id`, nem `adherence`, nem `grounding`.

A mecânica é a da etapa 6, deliberadamente repetida para que quem aprendeu uma não
precise aprender a outra:

- **Abrir a etapa dispara a pesquisa**, por `ResearchLegalCaseJurisprudence`
  (`POST /pecas/{id}/jurisprudencia/pesquisar`, com o `inference`). Ela chama
  `ResearchLegalCaseCourtDecisions` — os dois agentes mais o `LexmlRecordReader` —, grava
  as linhas por `SaveLegalCaseCourtDecisions` e o envelope em
  `legal_cases.court_decision_findings`. A inferência fica **fora** da transação.
- **O gatilho é a coluna ser nula, e nunca a lista estar vazia.** Mesma armadilha, mesma
  saída: uma rodada que abriu o portal e nada confirmou é resposta cara e legítima que
  grava zero linhas, e um gatilho pela lista repesquisaria a cada visita — substituindo
  em silêncio o que o advogado já curou, porque a gravação reconcilia por diff. A segunda
  rodada é o botão "Pesquisar novamente".
- **Tudo chega marcado, e o gesto é tirar.** As caixas de `CourtDecisionFields` vivem em
  estado local, como o `keep` das teses, e só viram gravação no "Concluir" — onde
  desmarcar vira **remoção**, pelo `whereNotIn` da Action irmã. O que ficou marcado é o
  que a minuta cita (ver "A minuta").

O nome das Actions segue uma regra que as duas etapas compartilham: a Action **da etapa**
leva o nome da etapa (`ResearchLegalCaseForensicReview`, `ResearchLegalCaseJurisprudence`)
e a que ela chama leva o nome do que os agentes acham (`...Theses`, `...CourtDecisions`).

`SaveLegalCaseCourtDecisions` não tem `asController()` — nada aponta para ela —, e não tem
mapa de ids: um julgado não é pai de nada, então não há chave que alguém esteja esperando.
O que ela repete de propósito é a guarda do portal: `CourtDecisionListData` descarta a
linha sem ementa e a que não aponta para um registro `/urn/`, porque no caminho de volta —
o payload do navegador — não há ninguém a quem relatar a recusa, e `source_url` é a única
razão pela qual uma ementa desta tabela pode ser conferida.

## A minuta

A oitava etapa que não é etapa. Concluir a análise de jurisprudência é o **primeiro gesto
do projeto que termina uma peça**: `FinalizeLegalCase` grava as teses e os julgados pelas
duas Actions irmãs, vira `is_draft` para `false` — até aqui nada escrevia essa coluna, e a
`LegalCasePolicy` documentava a ausência — e manda `PleadingDraftingAgent` redigir a
petição inteira.

A fronteira entre esses efeitos é o desenho. As duas gravações e a bandeira são **uma
transação**, porque são uma afirmação só sobre a peça: são estes os argumentos e estes os
julgados, e ela está pronta. A redação fica **fora**, com `try/catch` e `report()` — é a
única parte que sai da máquina e a única que uma cota esgotada pode levar embora.
Falhando, a peça continua registrada e a aba Minuta abre vazia oferecendo o botão de
gerar. Com uma versão na mão, o mesmo `GenerateLegalPleading` é o **"Gerar novamente"**:
redige sobre a peça como ela está agora e grava a versão seguinte. Ele já foi recusado
aqui, quando regerar parecia escrever por cima do advogado; o append-only desfez a
objeção — a correção fica na tabela como versão anterior —, e a tela confirma antes
porque ela sai da aba mesmo sem sair do banco.

A peça passa a ter **duas abas**, URLs de verdade como as da conta: `/pecas/{id}/editar`
e `/pecas/{id}/minuta`. A segunda é um cabeçalho fixo com o timbre do escritório e um
textarea embaixo. **O timbre é moldura, não conteúdo**: nome, OAB, endereço e telefone
vêm da conta e do usuário logado por `PleadingLetterhead` e nunca passam por modelo —
um número de OAB inventado num documento protocolado não tem contrapartida. Pelo mesmo
motivo a assinatura é composta em PHP (`PleadingSignature`, com tabela de meses própria
em vez de `locale()`), e o agente para em "Nestes termos, pede deferimento."

`legal_pleadings` é 1-N e **append-only**: editar nunca sobrescreve, grava a versão
seguinte, e por isso é a única filha de `LegalCase` sem `softDeletes`. Texto idêntico
não grava nada — senão abrir e clicar em Salvar encheria o histórico de versões que
diferem só no timestamp. `StoreLegalPleadingVersion` é o único lugar que cunha uma
versão, com `lockForUpdate()`, e o `unique(legal_case_id, version)` é o que torna a
numeração um fato. **Editar não chama agente nenhum**: corrigir um parágrafo não custa
inferência; regerar é um gesto separado e explícito.

A aba exporta **PDF** (`ExportLegalPleadingPdf`, dompdf) e **DOCX**
(`ExportLegalPleadingDocx`, PhpWord), ambos com o timbre e a régua ABNT da tela, e
ambos da **última versão salva** — a tela desliga o botão com alteração pendente, para
que o arquivo seja sempre uma versão que o histórico conhece. O timbre é recomposto na
exportação por `PleadingLetterhead::lines()`, que é o único lugar em PHP com as máscaras
de telefone e CEP; no DOCX ele vai para o cabeçalho da seção, onde o Word o repete e a
edição do corpo não o alcança. Qual parágrafo é citação é decidido por
`PleadingBlocks`, **gêmeo** do `blocksOf()` de `pleading-document.tsx`: mexer num é mexer
no outro. Duas armadilhas do PhpWord, ambas geram arquivo que o Word chama de corrompido:
ele não escapa `&` sem `Settings::setOutputEscapingEnabled(true)`, e o `Converter` devolve
twips fracionados onde o schema só aceita inteiros.

A guarda central do agente não é a cifra, é a **lacuna**. A qualificação das partes
exige estado civil e profissão; um modelo escreve "brasileiro, casado, comerciante"
porque é gramaticalmente obrigatório, banal e errado sobre uma pessoa real. Então tudo
o que o dossiê não traz vira marcador entre colchetes — `[estado civil]`, `[CIDADE/UF]`
— e `PleadingDraftData` os conta para a tela dizer "7 lacunas a preencher". A guarda de
cifra é a mesma de `RefinedFactsData`, com a fonte alargada: um valor que o advogado já
escreveu num pedido autoriza tanto quanto o relato.

A regra não mudou quando o cadastro passou a guardar a qualificação — mudou a chance de
a lacuna aparecer. `customers` tem `marital_status` (o enum `MaritalStatus`), `occupation`
e `birth_date`, os três **opcionais e exclusivos de pessoa física**: o `exclude_if` de
`ValidatesCustomer` descarta o que vier de uma pessoa jurídica, e `QualificationData`
é o objeto que os carrega, irmão de `AddressData` pelo mesmo motivo — "qualificação" é
uma parte do documento, não três colunas soltas. Um cadastro sem eles continua válido,
`written()` continua descartando a linha vazia do dossiê, e é essa ausência que manda
escrever `[estado civil]`.

Os três chegam ao agente, mas o terceiro chega **convertido**: a data de nascimento
continua fora de `forDrafting()` e quem entra é a idade que `LegalCaseDossier::age()`
deriva dela — "Idade: 47 anos". O motivo de excluir a data segue de pé (a qualificação
padrão não declara aniversário, e um campo no dossiê é um convite a escrevê-lo); o que se
declara é a idade, então é a idade que viaja. A subtração é feita em PHP pela mesma razão
que a assinatura é: pedir uma conta a este agente é o que o resto do prompt gasta páginas
proibindo.

E a idade é a **única** lacuna que não vira colchete. Estado civil e profissão o parágrafo
exige, então a falta deles é `[estado civil]`; a idade ele não exige, então a falta dela é
silêncio — `[idade]` não está na lista de marcadores e o prompt diz por quê. Uma data no
futuro cai no mesmo silêncio, porque `age()` corta em zero em vez de qualificar o autor
com idade negativa.

`LegalCaseDossier::forDrafting()` é a terceira projeção, e a mais larga: qualifica as
duas partes com endereço inteiro, mascara o documento (vai copiado para um parágrafo
que um juiz lê), leva as teses e leva os **julgados da etapa 7**. **Não leva os
precedentes**: são achados sobre uma tese, e não os documentos que o advogado escolheu
citar. A instrução negativa do prompt ("nada se cita que o dossiê não traga") se apoia no
dossiê carregar uma lista só de julgados. `LegalCaseDossierTest` fixa as duas coisas.

**O agente põe o julgado, mas não o escreve.** Cada julgado chega ao dossiê com um
marcador — `[[JULGADO 1]]` — e o agente escreve o marcador sozinho num parágrafo, no fim
da tese que o julgado corrobora, dentro de `DO DIREITO`, depois de uma frase que o
apresenta ("Nesse sentido, é o entendimento do Superior Tribunal de Justiça:").
`PleadingJurisprudence::expand()`, chamado por `DraftLegalPleading`, troca o marcador pela
ementa entre aspas e pela referência entre parênteses, os dois como parágrafos `>` — que
`PleadingBlocks` e `blocksOf()` já recuam 4 cm, na tela, no PDF e no DOCX. É o argumento
do timbre aplicado à citação: a ementa é cópia do registro do LexML, e uma ementa
reescrita de memória tem a forma exata de uma verdadeira. A referência para onde o
registro para — o LexML não traz relator nem DJe, então ela termina no órgão julgador e
na data. O campo Ementa do LexML traz a certidão do julgamento colada ao fim ("Decisão
Vistos e relatados…"), e `PleadingJurisprudence::ementa()` a corta: a petição cita a
ementa. Colchete duplo não é lacuna, colchete simples é. Um marcador escrito no meio de
uma frase, ou que aponta para um julgado que não existe, fica no texto, e o
`PleadingDraftData` o conta como lacuna.

A numeração é a posição na relação `courtDecisions`, e o dossiê e a expansão leem a
**mesma** coleção. Como `FinalizeLegalCase` apaga o que o advogado desmarcou *antes* de
redigir, a relação é exatamente a lista que ele manteve.

**A citação pode sair abreviada, e continua sendo cópia.** Uma ementa de dois mil
caracteres enterra a frase que sustenta a tese, então o dossiê traz cada ementa em
**trechos numerados** — `PleadingJurisprudence::passages()` corta o cabeçalho e depois
cada frase ou item — e o agente devolve, em `excerpts`, só os **números** dos trechos que
a citação guarda. `abridge()` recoloca os escolhidos na ordem do tribunal e marca cada
corte com `[...]`. Duas coisas não dependem da escolha: o **cabeçalho** em caixa alta da
ementa (`heading()`, até o primeiro item "1." ou "- ") entra sempre, e a **referência** —
título, órgão, "julgado em dd/mm/aaaa" — continua composta das colunas. Número que não
nomeia trecho é ignorado, e sem nenhum válido a ementa vai inteira, como antes. A
abreviação vive **só no documento**: `court_decisions.summary` não muda, e a etapa 7
continua mostrando a ementa completa. `[...]` não é lacuna — `gapsIn()` exige letra.

Números, e não texto, por uma armadilha medida: pedida a cópia literal dos trechos, o
Gemini devolveu **zero tokens** com `finishReason: RECITATION` — a ementa é texto público
que o filtro reconhece — e a minuta inteira se perdia, com o `DraftLegalPleading`
lançando "minuta vazia". O SDK achata isso em `FinishReason::ContentFilter`; só o corpo
cru da resposta diz `RECITATION`. Nenhum agente deste projeto deve ser instruído a
reproduzir ementa, súmula ou texto de lei palavra por palavra.

**O DO DIREITO passa por um segundo agente antes de a versão ser gravada.**
`PleadingGroundsReinforcementAgent`, exposto por `ReinforcePleadingGrounds`, recebe o
**corpo** da seção — `PleadingSections` o recorta entre o título romano e o seguinte, e o
recoloca sob o título original, porque a numeração não é dele — e reescreve o argumento
tese por tese: a subsunção (o que o dispositivo exige, o fato do relato que o preenche, a
consequência que leva ao pedido), com a espécie da tese mudando a redação — o mérito
subsidiário abre por "subsidiariamente". O material são as teses **inteiras**:
`LegalCaseDossier::forGrounds()` é a quinta projeção, com espécie, argumento, garantia e
cada fundamento numa linha com tipo e fonte, mais pedidos e julgados — e sem as partes.
Sem tese na peça, o agente não é chamado.

A diferença para as guardas da redação é que aqui há sempre para onde voltar, então
`ReinforcedGroundsData` **recusa** em vez de relatar: marcador de julgado perdido,
repetido ou inventado, súmula ou tema que o dossiê não escreve, cifra sem fonte. A
recusa vira exceção, e `DraftLegalPleading::reinforced()` a reporta e segue com a seção
da redação — o reforço é o único passo da minuta que pode falhar sozinho, e custa
qualidade, nunca a peça. São duas inferências em série no "Concluir" e no "Gerar
novamente", dentro dos 900 s do `AllowLongInference`.

## Ainda não implementado

O módulo de Jurisprudência: ingestão, chunking e busca vetorial sobre o corpus.
O pgvector já está de pé e em uso no catálogo de classes, então o que falta é a
tabela do corpus, o pipeline de ingestão e o `SimilaritySearch` no agente. Lá o
índice ANN passa a valer — no catálogo, com 615 linhas, o scan exato é mais
rápido do que a perda de recall compensaria.
