# ProceduralClass — a classe processual

`App\Domain\ProceduralClasses\Models\ProceduralClass` · tabela `procedural_classes`

## Em uma frase

A **classe processual do CNJ**: o rótulo oficial sob o qual um processo é
autuado quando entra no Judiciário — "Procedimento Comum Cível" (7),
"Usucapião" (49), "Ação Trabalhista - Rito Ordinário" (985) — e, para o LexIA,
a escolha que define o rito, a base legal e como as partes são chamadas no
texto da peça.

## O conceito

Todo processo judicial brasileiro é classificado, no momento da distribuição,
segundo as **Tabelas Processuais Unificadas (TPU)** do CNJ (Resolução CNJ nº
46/2007). A TPU tem três tabelas; esta é a de **classes**, que responde "que
tipo de procedimento é este?". As outras duas são **assuntos** (matéria
discutida — ver [practice-area.md](practice-area.md)) e **movimentos**
(andamentos).

A classe não é decorativa: ela determina o **rito** (a sequência de atos
processuais), aparece no cabeçalho dos autos, viaja nas integrações com PJe e
DataJud e é o que o sistema do tribunal usa para distribuir o processo à vara
competente. Escolher a classe errada numa petição inicial é erro processual
concreto, não de etiqueta.

A tabela é **hierárquica**: dez raízes por natureza do processo, e dentro delas
agrupamentos até chegar na folha selecionável. Só as folhas são importadas —
os nós intermediários não são escolhíveis, e por isso o caminho até a raiz vem
denormalizado em `path`.

| `root_code` | Raiz | Classes importadas |
|---|---|---|
| 2 | PROCESSO CÍVEL E DO TRABALHO | 219 |
| 1310 | SUPREMO TRIBUNAL FEDERAL | 87 |
| 268 | PROCESSO CRIMINAL | 84 |
| 547 | PROCEDIMENTOS DE INFÂNCIA E JUVENTUDE | 60 |
| 5 | SUPERIOR TRIBUNAL DE JUSTIÇA | 54 |
| 11427 | PROCESSO ELEITORAL | 54 |
| 1198 | PROCEDIMENTOS ADMINISTRATIVOS | 33 |
| 11028 | PROCESSO MILITAR | 16 |
| 385 | EXECUÇÃO PENAL E DE MEDIDAS ALTERNATIVAS | 6 |
| 11099 | PROCEDIMENTOS PRÉ-PROCESSUAIS DE RESOLUÇÃO CONSENSUAL DE CONFLITOS | 2 |

## Como o LexIA modela

Dado de **referência**, como `PracticeArea`: sem `account_id`, sem
`SoftDeletes`, carregado por migration. 615 classes ativas.

| Coluna | Tipo | Papel |
|---|---|---|
| `id` | uuid | chave primária, local a cada banco |
| `code` | integer, **único** | **a identidade estável** — o identificador do próprio CNJ, o número que viaja em DataJud/PJe e consta dos autos |
| `name` | string | nome oficial |
| `slug` | string | nome normalizado, para URL e busca |
| `root_code` | integer | raiz da hierarquia (tabela acima) |
| `path` | jsonb, `array` | caminho completo da raiz até a folha, denormalizado |
| `abbreviation` | string(40), nullable | sigla do CNJ (`ProceComCiv`, `ATOrd`) |
| `nature` | string(60), nullable | natureza segundo o CNJ — **string suja de propósito** (ver abaixo) |
| `legal_norm` / `legal_article` | string, nullable | norma e artigo que fundamentam a classe ("CPC 2015" / "318") |
| `active_party` / `passive_party` | string(80), nullable | como se chamam as partes nessa classe |
| `has_own_numbering` | boolean | recebe número de processo próprio, ou tramita dentro dos autos de outro |
| `is_filing_class` | boolean | abre processo novo |
| `is_cross_cutting` | boolean | incidente, recurso, carta, cumprimento de sentença |
| `jurisdictions` | jsonb, `array` | competências do CNJ em que a classe pode ser usada |

Índices: `code` único, `root_code`, `is_filing_class`, e um **GIN** em
`jurisdictions` — o filtro é de continência ("quais classes valem em
`just_es_1grau`?"), que btree não responde.

`code` é único mas **não é a primary key**: as chaves estrangeiras seguem uuid
em todo o projeto. Em fixtures e testes, busque pelo `code`, nunca pelo uuid.

### Os dois booleanos, e por que não use `nature`

`nature` chega do CNJ com 33 valores distintos (mais `null`) para o que deveria
ser um conjunto fechado, incluindo typos (`Cohecimento`, `Recursaç`) e
variações de caixa (`Incidente` / `incidente`, `Pré-Processual` /
`Pré-processual`). Está guardado como string livre exatamente por isso: serve
de referência bruta, **não deve ser usado em branch de código**. O que o
produto consulta são os dois booleanos, já computados na geração do JSON:

- **`is_filing_class`** — 326 das 615. É o filtro de uma tela de petição
  inicial.
- **`is_cross_cutting`** — 182. Incidentes, recursos, cartas precatórias,
  cumprimento de sentença. Nunca ofereça numa inicial.

Os dois são **independentes e não exaustivos**: nenhuma classe é as duas
coisas, e **107 não são nenhuma das duas** (recursos de instância superior como
Recurso Especial e Recurso Extraordinário, restauração de autos, medidas
preparatórias, procedimentos administrativos). Não escreva
`!is_filing_class ⇒ is_cross_cutting`: é falso para 107 linhas.

### Competências (`jurisdictions`)

29 valores possíveis, no formato *ramo de justiça × grau*. Média de 4,2 por
classe. A lista completa:

```
just_es_1grau        just_es_2grau        just_es_1grau_mil    just_es_2grau_mil
just_es_juizado_es   just_es_juizado_es_fp just_es_turmas
just_fed_1grau       just_fed_2grau       just_fed_juizado_es
just_fed_turmas      just_fed_regional    just_fed_nacional
just_trab_1grau      just_trab_2grau      just_trab_tst        just_trab_csjt
just_elei_1grau      just_elei_2grau      just_elei_tse
just_mil_est_1grau   just_mil_est_tjm     just_mil_uniao_1grau just_mil_uniao_stm
just_tu_es_un        stf                  stj                  cjf   cnj
```

Lendo o prefixo: `just_es` = Justiça Estadual, `just_fed` = Federal,
`just_trab` = do Trabalho, `just_elei` = Eleitoral, `just_mil_est` e
`just_mil_uniao` = Militar estadual e da União; `juizado_es` = juizado
especial, `turmas` = turmas recursais, `stf`/`stj`/`tst`/`tse`/`stm` = os
tribunais superiores, `cnj` e `cjf` = os conselhos (processos administrativos).

Advogado trabalhista não tem uso para o juizado da fazenda pública: filtre por
competência antes de qualquer outra coisa ao montar um select. **Ressalva:** 33
classes têm `jurisdictions` vazio (o CNJ não informou) — um filtro estrito as
esconde, e uma delas é classe de abertura. Decida explicitamente se o filtro
inclui ou exclui o conjunto vazio.

### Métodos

```php
$class->legalBasis();              // "CPC 2015, art. 318" — ou só a norma, ou null
$class->appliesIn('just_es_1grau'); // a classe vale nessa competência?
$class->practiceAreas;              // BelongsToMany, ordenado por position
```

### Muitos-para-muitos com a área

Uma classe serve várias áreas: a 7 ("Procedimento Comum Cível") aparece em 15
das 24 áreas. 72 classes estão em mais de uma área; todas as 615 estão em pelo
menos uma. Por isso não existe `practice_area_id` aqui — a coluna duplicaria a
linha e faria o `code` perder o papel de chave natural única. O vínculo vive em
`practice_area_procedural_class`, com o `scope` explicado em
[practice-area.md](practice-area.md).

## Exemplo completo

```json
{
  "code": 985,
  "name": "Ação Trabalhista - Rito Ordinário",
  "slug": "acao-trabalhista-rito-ordinario",
  "root_code": 2,
  "path": ["PROCESSO CÍVEL E DO TRABALHO", "Processo de Conhecimento",
           "Procedimento de Conhecimento", "Procedimentos Trabalhistas",
           "Ação Trabalhista - Rito Ordinário"],
  "abbreviation": "ATOrd",
  "nature": "Conhecimento",
  "legal_norm": "CLT", "legal_article": "arts. 840 e ss",
  "active_party": "Autor", "passive_party": "Réu",
  "has_own_numbering": true,
  "is_filing_class": true, "is_cross_cutting": false,
  "jurisdictions": ["just_trab_1grau", "just_trab_2grau", "just_trab_tst"]
}
```

Os pares de partes mais comuns no catálogo são Requerente/Requerido (168
classes), Autor/Réu (75), Recorrente/Recorrido (37), Embargante/Embargado (23),
Agravante/Agravado (22), Impetrante/Impetrado (16) e Exequente/Executado (16);
49 classes não informam nenhum dos dois. **É daqui que sai a nomenclatura das
partes no texto da peça** — não de convenção fixa no código.

## Fonte da verdade

- Model: `app/Domain/ProceduralClasses/Models/ProceduralClass.php`
- Migration da tabela e do pivot: `database/migrations/2026_09_15_130001_create_procedural_classes_table.php`
- Migration da carga: `database/migrations/2026_09_15_130003_seed_procedural_catalog.php`
- Dado: `database/data/procedural-classes.json` (615 linhas; origem, versão da
  TPU de 12/09/2026 e rotina de ressincronização em `database/data/README.md`)
- Tipo no front: `ProceduralClass` em `resources/js/types/index.d.ts`
- Testes: `tests/Feature/Catalog/ProceduralCatalogTest.php`
