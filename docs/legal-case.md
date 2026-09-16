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

O que existe hoje é o esqueleto: a quem pertence, para quem é, e onde fica na
taxonomia processual.

| Coluna | Tipo | Papel |
|---|---|---|
| `id` | uuid | chave primária |
| `account_id` | uuid FK → `accounts` | a conta que redige; `cascadeOnDelete` |
| `customer_id` | uuid FK → `customers` | o cliente atendido; `cascadeOnDelete` |
| `practice_area_id` | uuid FK → `practice_areas` | área escolhida; `restrictOnDelete` |
| `procedural_class_id` | uuid FK → `procedural_classes` | classe escolhida; `restrictOnDelete` |
| `created_at` / `updated_at` / `deleted_at` | timestamp | `SoftDeletes` |

Índices: `(account_id, customer_id)` e `(account_id, practice_area_id)` — toda
listagem parte da conta.

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

### Relações

```php
$case->account;          // BelongsTo Account
$case->customer;         // BelongsTo Customer
$case->practiceArea;     // BelongsTo PracticeArea
$case->proceduralClass;  // BelongsTo ProceduralClass
```

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

Nenhuma Action, rota, Policy, tela ou coluna de conteúdo. O que o produto
precisa acrescentar, na ordem em que a peça se escreve:

- **a parte contrária (réu)** — hoje só o cliente está modelado, e nem sequer
  está registrado em que polo ele está. Um cliente pode ser autor numa peça e
  réu em outra (defesa); a modelagem futura precisa dos dois lados, e o réu
  nem sempre é um `Customer` (é o adversário, não um cliente do escritório);
- **os fatos**, em texto;
- **as tutelas e os pedidos**;
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
- Testes: `tests/Feature/LegalCases/ManageLegalCasesTest.php`
