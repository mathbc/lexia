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

## Comandos

```bash
docker compose up -d                 # Postgres + pgvector na porta 5442
php artisan migrate:fresh --seed     # senha de todos os usuários: password
composer dev                         # serve + queue + pail + vite
php artisan test                     # PHPUnit contra o banco lexia_testing
./vendor/bin/pint                    # formatação
./vendor/bin/phpstan analyse         # nível 6, sem supressões
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

`AccountAdmin` > `Admin` > `Lawyer`, todos internos à conta. A regra
transversal é que **ninguém gerencia um par ou um superior**
(`UserRole::outranks()` é estrito), o que impede dois admins de se trancarem
mutuamente. `users.platform_admin` é a equipe LexIA e fica fora do enum de
propósito.

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
