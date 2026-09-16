# Glossário

Vocabulário jurídico brasileiro usado nos outros documentos e nos rótulos da
UI. Definições operacionais — o suficiente para ler o domínio com precisão, sem
ambição de rigor doutrinário.

## Processo e peças

**Peça (processual)** — qualquer documento que um advogado produz e protocola
num processo: petição inicial, contestação, réplica, recurso, manifestação. É o
que o LexIA redige; a entidade correspondente é `LegalCase`.

**Petição inicial** — a peça que inaugura o processo. Seus requisitos estão no
art. 319 do CPC/2015: partes, fatos, fundamentos jurídicos, pedido, valor da
causa, provas.

**Contestação** — a peça de defesa do réu.

**Autos** — o conjunto de documentos do processo. "Tramitar nos mesmos autos"
significa correr dentro do processo já existente, sem número novo — o que
`has_own_numbering = false` registra.

**Distribuição / autuação** — o ato de registrar o processo no tribunal, quando
ele recebe número e **classe processual**, e é sorteado para uma vara.

**Rito (procedimento)** — a sequência de atos que o processo segue. Ordinário,
sumaríssimo, especial. É determinado pela classe escolhida.

**Instância / grau** — nível de julgamento. 1º grau julga primeiro; 2º grau
(tribunal) julga o recurso; tribunais superiores (STF, STJ, TST, TSE, STM)
julgam questões de direito federal ou constitucional.

**Competência** — a fatia do Judiciário autorizada a julgar determinada causa,
por matéria, pessoa ou território. No catálogo do CNJ é o cruzamento *ramo da
justiça × grau*, e é o conteúdo de `ProceduralClass::$jurisdictions`.

## Partes

**Polo ativo** — quem propõe a ação. **Polo passivo** — contra quem ela é
proposta.

**Autor / Réu** — a nomenclatura mais comum do par, no processo civil de
conhecimento. Outras classes usam outros nomes, e é por isso que eles são dado
e não constante: Requerente/Requerido, Exequente/Executado (execução),
Impetrante/Impetrado (mandado de segurança), Recorrente/Recorrido,
Agravante/Agravado, Embargante/Embargado, Reclamante/Reclamado (trabalhista).
`ProceduralClass::$active_party` e `$passive_party` trazem o par correto.

**Cliente** — quem contrata o escritório (`Customer`). Pode estar em qualquer
um dos polos: é autor quando o escritório propõe a ação, réu quando o
escritório o defende.

## Pedidos e decisões

**Pedido** — o que se requer ao juiz. É o núcleo da peça; a sentença fica
limitada a ele.

**Tutela provisória** — decisão antecipada, antes do julgamento final, quando
esperar causaria dano (tutela de **urgência**) ou quando o direito já está
demonstrado de plano (tutela de **evidência**). CPC/2015, arts. 294 e ss. É o
que o produto chama de "tutelas".

**Sentença** — decisão que encerra a fase de conhecimento no 1º grau.
**Acórdão** — decisão colegiada de tribunal.

**Cumprimento de sentença / execução** — a fase em que se cobra o que foi
decidido. No catálogo é classe transversal (`is_cross_cutting`), não de
abertura.

**Recurso** — pedido de revisão da decisão à instância superior (apelação,
agravo, embargos de declaração, recurso especial, recurso extraordinário).

**Incidente** — questão processual que corre paralela ao processo principal.

**Carta precatória** — pedido de um juízo a outro para praticar ato fora de sua
competência territorial.

## CNJ e integrações

**CNJ** — Conselho Nacional de Justiça, órgão de controle administrativo do
Judiciário. Publica e mantém as tabelas padronizadas.

**TPU** — Tabelas Processuais Unificadas (Resolução CNJ nº 46/2007). Três
tabelas: **classes** (que tipo de procedimento), **assuntos** (que matéria se
discute) e **movimentos** (que andamentos ocorreram). O LexIA importa hoje a de
classes; a de assuntos é destino futuro de
`PracticeArea::$cnj_subject_roots`.

**SGT** — Sistema de Gestão de Tabelas do CNJ, o web service SOAP público de
onde os JSON em `database/data/` foram extraídos.

**PJe** — Processo Judicial Eletrônico, sistema de tramitação usado por boa
parte dos tribunais.

**DataJud** — base nacional de dados do Judiciário, alimentada pelos tribunais.
É onde o `code` de `ProceduralClass` aparece como identificador da classe.

## Ramos do direito citados

**Material × processual** — o direito material define o direito em si (quem
deve o quê); o processual define como pedi-lo em juízo.

**Público × privado** — privado rege relações entre particulares (civil,
empresarial); público rege relações com o Estado (administrativo, tributário,
penal).

Os 24 rótulos usados no produto estão em
[practice-area.md](practice-area.md#o-catálogo-atual). Dois deles não são ramos
do direito no sentido estrito e existem por conveniência de produto:
`tribunais-superiores` (recorte por tribunal) e `processual-geral` (classes
transversais a qualquer área).

## Documentos e identificadores

**CPF** — cadastro de pessoa física, 11 dígitos. **CNPJ** — cadastro de pessoa
jurídica, 14 dígitos. Em `Customer`, exatamente um dos dois é preenchido,
conforme `CustomerType` (`Individual` / `Company`).

**OAB** — Ordem dos Advogados do Brasil; a inscrição que habilita o advogado.

**Valor da causa** — expressão econômica do pedido, obrigatória na inicial;
define custas e, às vezes, competência.
