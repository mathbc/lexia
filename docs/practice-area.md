# PracticeArea — a área de atuação

`App\Domain\PracticeAreas\Models\PracticeArea` · tabela `practice_areas`

## Em uma frase

O **ramo do direito** em que a peça se enquadra — Direito Civil, Direito do
Trabalho, Direito Tributário — e a primeira escolha que o advogado faz ao
redigir, porque é ela que reduz o catálogo de 615 classes processuais a uma
lista utilizável.

## O conceito

O direito se divide em ramos por natureza da relação jurídica: público ×
privado, material × processual, e daí em diante (civil, penal, trabalhista,
tributário…). Escritórios se organizam por esses ramos — é como o advogado
pensa e como o próprio mercado se apresenta ("atuo em Direito de Família").

### A taxonomia é do LexIA, não do CNJ

Ponto central, e a causa da maioria das confusões possíveis aqui:

- A **Tabela de Classes** do CNJ é organizada por *natureza do processo* (dez
  raízes: Processo Cível e do Trabalho, Processo Criminal, Processo
  Eleitoral…), nunca por ramo do direito.
- Quem é organizado por ramo é a **Tabela de Assuntos** do CNJ, cujas raízes
  são literalmente as áreas. É para elas que aponta `cnj_subject_roots`.
- Mesmo assim a lista abaixo não é a de assuntos: **Direito Imobiliário e
  Direito Empresarial não existem como área no CNJ** (estão dentro de Direito
  Civil), e o advogado trabalha com eles como áreas separadas. Daí a curadoria.

Consequência: nenhum código do CNJ identifica uma área. A identidade é o
`slug`, escolhido por nós.

## Como o LexIA modela

Dado de **referência, compartilhado por todas as contas**: sem `account_id`,
sem `SoftDeletes`, sem Policy, sem tela de cadastro. As 24 linhas chegam por
migration (`2026_09_15_130003_seed_procedural_catalog.php`) e só mudam quando o
CNJ republica a TPU.

| Coluna | Tipo | Papel |
|---|---|---|
| `id` | uuid | chave primária, local a cada banco |
| `slug` | string(60), único | **a identidade estável** — sobrevive a ressincronização; é o que o dataset, as URLs e os testes falam |
| `label` | string(120) | rótulo em português exibido na UI |
| `cnj_subject_roots` | jsonb, `array` | raízes da Tabela de Assuntos do CNJ correspondentes; ponte para a importação futura de assuntos |
| `position` | smallint | ordem curada do select (não é alfabética: as áreas mais usadas vêm antes) |

Nunca referencie uma área pelo uuid em código, fixture ou seed: o uuid é
gerado na carga e difere entre bancos. Use o `slug`
(`PracticeArea::query()->where('slug', 'trabalhista')->sole()`).

### A relação com as classes

```php
public function proceduralClasses(): BelongsToMany
{
    return $this->belongsToMany(ProceduralClass::class)
        ->withPivot('scope')
        ->orderByPivot('scope', 'desc')  // 'specific' antes de 'generic'
        ->orderBy('name');
}
```

O pivot `practice_area_procedural_class` carrega **`scope`**:

- **`specific`** — classe própria daquela área (Inventário em Família,
  Usucapião em Imobiliário);
- **`generic`** — classe do tronco cível que serve qualquer área cível
  (Procedimento Comum Cível, Embargos de Terceiro, Cumprimento de Sentença).

A ordenação `desc` no scope não é estética: áreas como Família oferecem 74
classes, e as 33 que de fato pertencem à área têm que aparecer no topo. Um
select com 120 itens em ordem alfabética é inutilizável.

## O catálogo atual

24 áreas, 1.237 vínculos com as classes. `específicas`/`genéricas` são os
vínculos de cada scope:

| # | `slug` | `label` | `cnj_subject_roots` | específicas | genéricas |
|---|---|---|---|---|---|
| 1 | `civil` | Direito Civil | 899 | 0 | 41 |
| 2 | `familia-sucessoes` | Direito de Família e Sucessões | 899 | 33 | 41 |
| 3 | `imobiliario` | Direito Imobiliário | 899 | 18 | 41 |
| 4 | `consumidor` | Direito do Consumidor | 1156 | 6 | 41 |
| 5 | `empresarial` | Direito Empresarial e Falimentar | 899 | 16 | 41 |
| 6 | `trabalhista` | Direito do Trabalho | 864 | 90 | 0 |
| 7 | `previdenciario` | Direito Previdenciário | 195, 12734 | 2 | 41 |
| 8 | `tributario` | Direito Tributário | 14 | 7 | 41 |
| 9 | `administrativo` | Direito Administrativo e Fazenda Pública | 9985 | 21 | 41 |
| 10 | `constitucional` | Direito Constitucional | 9985, 12467 | 9 | 41 |
| 11 | `ambiental` | Direito Ambiental | 10110 | 3 | 41 |
| 12 | `saude` | Direito da Saúde | 12480 | 4 | 41 |
| 13 | `registros-publicos` | Registros Públicos e Notarial | 7724 | 4 | 41 |
| 14 | `maritimo` | Direito Marítimo | 1146 | 2 | 41 |
| 15 | `internacional` | Direito Internacional | 6191 | 1 | 41 |
| 16 | `penal` | Direito Penal e Processo Penal | 287, 1209 | 90 | 0 |
| 17 | `execucao-penal` | Execução Penal | 1209 | 6 | 0 |
| 18 | `penal-militar` | Direito Penal Militar | 11068, 11049 | 16 | 0 |
| 19 | `eleitoral` | Direito Eleitoral | 11428, 10739 | 54 | 0 |
| 20 | `infancia-juventude` | Infância e Juventude | 9633 | 60 | 0 |
| 21 | `educacao` | Direito à Educação | 12775 | 4 | 41 |
| 22 | `administrativo-judiciario` | Procedimentos Administrativos do Judiciário | 9985 | 33 | 0 |
| 23 | `tribunais-superiores` | Tribunais Superiores (STF/STJ) | — | 141 | 0 |
| 24 | `processual-geral` | Processual (transversal) | 8826 | 2 | 0 |

Leituras da tabela que orientam decisões de produto:

- **41 é o tronco cível.** Toda área de natureza cível recebe as mesmas 41
  classes genéricas. `civil` só tem elas — é a área "tronco", sem classe
  exclusiva.
- **Áreas de rito próprio não têm genéricas.** Trabalhista, penal, eleitoral,
  infância e juventude e militar não compartilham o tronco cível: rito,
  competência e nomenclatura das partes são outros.
- **`tribunais-superiores` não tem `cnj_subject_roots`.** É a única com o
  array vazio: agrupa as 141 classes originárias e recursais do STF e do STJ,
  que são um recorte por tribunal, não por ramo do direito. Não é uma área de
  atuação no sentido em que as outras são, e não deve ser oferecida numa tela
  de petição inicial comum.
- **`processual-geral` carrega só classes transversais** (2 vínculos) e
  nenhuma classe de abertura — sortear essa área ao gerar dados de teste deixa
  a escolha da classe sem opções válidas, o que o `LegalCaseFactory` já evita.

## Fonte da verdade

- Model: `app/Domain/PracticeAreas/Models/PracticeArea.php`
- Migration da tabela: `database/migrations/2026_09_15_130000_create_practice_areas_table.php`
- Migration da carga: `database/migrations/2026_09_15_130003_seed_procedural_catalog.php`
- Dado: `database/data/practice-areas.json` (e o `README.md` ao lado, que
  documenta origem, versão da TPU e ressincronização)
- Tipo no front: `PracticeArea` em `resources/js/types/index.d.ts`
- Testes: `tests/Feature/Catalog/ProceduralCatalogTest.php`
