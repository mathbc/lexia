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
  `pgvector/pgvector-php`; não instale nada para isso.
- `sebastian/complexity` está vendorizado mas **quebra em enums** — por isso a
  skill de complexidade usa `nikic/php-parser` direto.

## Ainda não implementado

`app/Ai/` e `app/Rag/` estão vazios, reservados para o módulo de
Jurisprudência (Ollama + `nomic-embed-text`, 768 dimensões).
