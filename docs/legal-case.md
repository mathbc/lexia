# LegalCase — a peça jurídica

`App\Domain\LegalCases\Models\LegalCase` · tabela `legal_cases`

## Em uma frase

A **peça jurídica** que o escritório redige para um cliente: o documento que o
advogado monta na plataforma, classificado pela área do direito e pela classe
processual do CNJ sob a qual será autuado.

## O conceito

Peça é qualquer documento que um advogado produz e protocola num processo. A
peça que abre o processo é a **petição inicial**; depois dela vêm contestação,
réplica, recursos, manifestações. O LexIA existe para produzir esse texto, e
`LegalCase` é a unidade de trabalho correspondente — um rascunho que pertence a
um cliente e carrega as decisões de enquadramento que o texto precisa respeitar.

Uma petição inicial tem partes que o produto vai precisar capturar (CPC/2015,
art. 319):

- **as partes** — quem pede (polo ativo) e contra quem se pede (polo passivo);
- **os fatos** — a narrativa do que aconteceu;
- **os fundamentos jurídicos** — o direito que decorre dos fatos;
- **os pedidos** — o que se pede ao juiz, inclusive **tutela provisória**
  (urgência ou evidência, CPC arts. 294 e ss.), quando o caso não pode esperar
  o fim do processo;
- **o valor da causa** e o requerimento de provas.

Ver [glossary.md](glossary.md) para cada um desses termos.

### O que `LegalCase` NÃO é

- **Não é o processo judicial.** Não tem número CNJ, tribunal, vara,
  distribuição nem andamento. É o documento *antes* de virar processo — ou uma
  peça avulsa dentro de um processo que o LexIA não acompanha. O nome em inglês
  ("case") pode sugerir o contrário; ignore a sugestão.
- **Não é o cliente nem o contrato.** Um `Customer` pode ter várias peças.
- **Não é o catálogo.** A área e a classe são apenas ponteiros para dado de
  referência compartilhado por todas as contas.

## Como o LexIA modela

O que existe hoje: a quem pertence, para quem é, onde fica na taxonomia
processual, contra quem é e o que aconteceu. Os anexos ficam em `documents`.

| Coluna | Tipo | Papel |
|---|---|---|
| `id` | uuid | chave primária |
| `account_id` | uuid FK → `accounts` | a conta que redige; `cascadeOnDelete` |
| `customer_id` | uuid FK → `customers` | o cliente atendido; `cascadeOnDelete` |
| `practice_area_id` | uuid FK → `practice_areas` | área escolhida; `restrictOnDelete` |
| `procedural_class_id` | uuid FK → `procedural_classes` | classe escolhida; `restrictOnDelete` |
| `defendant_*` | 12 colunas, todas nulas | a parte contrária, descrita inline |
| `court_addressing` | string, nula | o endereçamento — "Ao Juízo da 3ª Vara Cível da Comarca de Florianópolis/SC" |
| `facts` | longtext, nulo | a narrativa do que aconteceu |
| `injunctive_relief` / `injunctive_relief_description` | bool / longtext | a tutela de urgência, se houver |
| `current_step` | string, `'basics'` | até onde o preenchimento chegou (`LegalCaseStep`) |
| `is_draft` | bool, `true` | rascunho ou peça fechada |
| `created_at` / `updated_at` / `deleted_at` | timestamp | `SoftDeletes` |

Índices: `(account_id, customer_id)`, `(account_id, practice_area_id)` e
`(account_id, is_draft)` — toda listagem parte da conta.

O endereçamento é texto e não chave estrangeira porque o LexIA não mantém
tabela de tribunais nem de varas: quem sabe o endereçamento é o advogado, e a
forma varia com a justiça e com o costume local.

As duas políticas de deleção dizem coisas diferentes de propósito: a peça não
existe fora da conta nem fora do cliente (cascata), mas o catálogo é referência
e **não pode sumir debaixo de uma peça que o cita** (restrição). É por isso que
o `down()` da migration do catálogo falha em voz alta enquanto houver peça
apontando para ele.

### Multitenancy

`BelongsToAccount` aplica o `AccountScope` em toda query e preenche
`account_id` no `creating` a partir do usuário autenticado. Consequência
prática, exercitada em `ManageLegalCasesTest`: a Action **nunca** deve aceitar
`account_id` vindo do request. Sem usuário autenticado (fila, comando) o escopo
não se aplica — use `TenantContext::actingAs()` ou `acrossAllAccounts()`.

### Montagem em etapas

A peça é escrita em dias, não numa sentada, e por isso a linha existe muito
antes de estar completa. Cada "Continuar" do assistente salva a sua etapa e
avança `current_step`.

`current_step` é **marca d'água**: a etapa mais avançada já alcançada, e não a
última editada. Voltar à etapa 1 para corrigir o cliente é correção, não
retrocesso — sob a outra semântica a trilha trancaria as etapas já preenchidas
e a listagem diria "Dados básicos" para uma peça três quartos escrita. A regra
mora em `LegalCaseStep::furthest()` e é aplicada pelo trait
`AdvancesLegalCaseStep`, usado pelas seis Actions de escrita.

A etapa em que o navegador abre é coisa separada: vem do `?etapa` da URL, e cai
na marca d'água quando não há query. É o que permite regravar a etapa 1 sem que
a peça pareça ter recuado.

`is_draft` é a outra metade da resposta, e de propósito não é derivada da
etapa: chegar à última etapa não é o mesmo que declarar a peça pronta. Nada
ainda vira o valor para `false` — isso chega com a Action que finaliza.

### Relações

```php
$case->account;          // BelongsTo Account
$case->customer;         // BelongsTo Customer
$case->practiceArea;     // BelongsTo PracticeArea
$case->proceduralClass;  // BelongsTo ProceduralClass
$case->documents;        // HasMany Document
```

Os documentos são linha própria, ao contrário do réu: são muitos, cada um com
sua descrição, e entram e saem um a um. Ver [document.md](document.md).

## Invariantes

1. **A classe deve pertencer à área.** O banco não verifica: seriam duas FKs
   independentes e a checagem exigiria chave composta para dentro do pivot. A
   validação é da Action — a lista válida é `$area->proceduralClasses()`.
2. **Peça que inaugura processo precisa de classe de abertura.** Só as 326
   classes com `is_filing_class = true` autuam processo novo; oferecer
   `is_cross_cutting` (recurso, incidente, cumprimento de sentença) numa tela
   de petição inicial é erro de produto.
3. **Cliente e peça na mesma conta.** Garantido hoje pelo escopo do
   `Customer` na hora de resolver o `customer_id`; quando existir rota, a
   Policy é a defesa real (route-model binding roda antes do middleware de
   tenant — ver `CLAUDE.md`).

## O que ainda não existe

A peça já é escrita e salva: `POST /pecas` a abre, e
`/pecas/{legalCase}/dados-basicos`, `/reu`, `/fatos` e `/pedidos` gravam uma
etapa cada. `GET /pecas/{legalCase}/editar` a reabre onde parou.

**Os documentos são a exceção**: o rascunho carrega o próprio `File`, e onde
guardá-lo é decisão que ainda não foi tomada — a etapa 5 segue em estado local
no navegador, e o "Continuar" dela só avança `current_step`, via
`PATCH /pecas/{legalCase}/etapa`. O que o produto precisa acrescentar:

- **o upload dos documentos**, e o storage que ele exige;
- **a finalização**, que é o que vira `is_draft` para `false` — e com ela a
  regra de que peça fechada não se edita, hoje ausente da Policy de propósito;
- **o tipo de peça** (inicial, contestação, recurso…), que hoje está implícito
  na classe escolhida;
- **o assunto CNJ**, que é a outra tabela da TPU e ainda não foi importada —
  `PracticeArea::$cnj_subject_roots` é a ponte deixada pronta para ela;
- **o texto redigido**, e a ligação com o módulo de Jurisprudência (`app/Ai`,
  `app/Rag`, ainda vazios).

Ao nomear as partes no texto gerado, a nomenclatura correta vem da classe, não
de convenção fixa: `active_party`/`passive_party` de `ProceduralClass` dizem se
o par é Autor/Réu, Exequente/Executado, Impetrante/Impetrado, Requerente/
Requerido etc.

## Fonte da verdade

- Model: `app/Domain/LegalCases/Models/LegalCase.php`
- Migration: `database/migrations/2026_09_15_130002_create_legal_cases_table.php`
- Factory: `database/factories/LegalCaseFactory.php` (sorteia área e classe já
  existentes no banco e respeita o par área↔classe)
- Enum das etapas: `app/Domain/LegalCases/Enums/LegalCaseStep.php`
- Actions de escrita: `app/Domain/LegalCases/Actions/` (`CreateLegalCase`,
  `UpdateLegalCaseBasics`, `UpdateLegalCaseDefendant`, `UpdateLegalCaseFacts`,
  `SaveLegalCaseRequirements`, `AdvanceLegalCaseStep`)
- Testes: `tests/Feature/LegalCases/ManageLegalCasesTest.php` (model e schema),
  `SaveLegalCaseStepsTest.php` (o fluxo de montagem),
  `tests/Feature/Requirements/SaveLegalCaseRequirementsTest.php`
