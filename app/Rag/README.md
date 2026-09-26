# RAG

O conhecimento que os agentes de `app/Ai` leem. Markdown em `knowledge/`, carregado por
`KnowledgeBase`.

| Documento | Usado por |
|---|---|
| `knowledge/practice-areas.md` | `PracticeAreaClassificationAgent` |
| `knowledge/procedural-classes.md` | `ProceduralClassSelectionAgent` |

## Duas recuperações diferentes, e por quê

O projeto usa os dois regimes, cada um onde ganha.

**O markdown vai inteiro.** `KnowledgeBase::get()` devolve o arquivo completo, e para a
classificação de área isso é melhor que similaridade, não uma simplificação: o guia
descreve 24 opções mutuamente exclusivas em poucos kilobytes, e um corte top-k só
poderia **remover** candidatos — justamente quando o relato é vago, que é o caso que
mais precisa de todas as opções na mesa. Vale igual para os desempates do guia de
classes: "não confundir X com Y" não é recuperável por similaridade, porque o trecho
relevante é justamente o que fala da opção *errada*.

**O catálogo de classes é vetorizado.** Aqui o corpus não cabe: as 615 classes
enriquecidas somam ~1 MB, e só as candidatas da maior área já passam de 30 KB. A coluna
`vector(768)` em `procedural_classes` e `ProceduralClassRankingQuery` decidem quais
candidatas o prompt descreve por inteiro e quais chegam só pelo nome.

A distinção que importa: o vetor **nunca** remove uma candidata do `enum`. Ele escolhe
onde gastar a janela, não quais respostas são possíveis — que é exatamente a objeção ao
top-k acima, evitada por construção.

**Os temas do STJ são o terceiro regime, e o único top-k de verdade.** As 2.364 linhas de
`legal_themes` (Temas Repetitivos, Controvérsias, PUIL, IAC, SIRDR) chegam pelo
`lexia:import-legal-themes` já com o vetor, e a aba Temas da etapa 6 as lê:
`LegalThemeCandidatesQuery` ordena no Postgres (`<=>`) pela proximidade com o relato e
entrega os `ai.retrieval.theme_candidates` (12) mais próximos a
`LegalThemeSelectionAgent`, que fica com os que se aplicam. Ali o corte é o regime certo,
ao contrário do enquadramento: não há `enum` fixo a proteger — o `enum` é montado com as
candidatas que a busca trouxe —, e sim um corpus grande do qual só uns poucos temas
importam para uma peça.

Três diferenças para o catálogo de classes, todas deliberadas:

- **A distância é calculada no SQL**, e não em PHP: hidratar 2,4 mil vetores para ficar
  com doze seria pagar a tabela pelo topo dela. O vetor da consulta vai como array — uma
  string faria o framework embuti-la por conta própria, sem o prefixo `search_query:`.
- **A consulta leva a área na frente** (`EmbedLegalThemes::queryFor()`): é o pedaço do
  enquadramento que fala do assunto. A classe fica de fora, porque "Procedimento Comum
  Cível" é igual para metade das peças.
- **Não degrada em silêncio.** Sem o vetor da consulta a exceção sobe e a aba falha:
  não existe ordem de reserva que signifique alguma coisa, e uma lista vazia seria
  gravada como "nenhum tema se aplica". Cancelados e prejudicados ficam fora do filtro.

## Como a vetorização funciona

- Embeddings via Ollama com `nomic-embed-text`, 768 dimensões, em lotes de 64. O laço, o
  hash e o prefixo moram em `DocumentEmbedder` (`app/Domain/Shared/Support`), que as duas
  Actions usam (`EmbedProceduralClasses`, `EmbedLegalThemes`). Cada uma decide só o texto
  de cada linha.
- Os textos levam os prefixos de tarefa do nomic: `search_document:` no corpus e
  `search_query:` na consulta (`DocumentEmbedder::document()` e `::query()`). O modelo
  foi treinado com eles, e a similaridade entre um relato leigo e uma definição jurídica
  piora visivelmente sem eles.
- Coluna com os helpers nativos do core do Laravel 12
  (`Schema::ensureVectorExtensionExists()`, `$table->vector('embedding', 768)`).
- `embedding_hash` guarda o sha256 do texto embutido: uma linha que não mudou não é
  reembutida, e a migration de recarga do catálogo zera os hashes para forçar a próxima
  passada. No tema, o texto é questão, tese, delimitação e assuntos. A situação fica fora
  de propósito: ela muda sem que o tema mude, e dentro do texto trocaria o hash.
- **Sem índice ANN**, de propósito: a 615 linhas o scan exato leva menos de dois
  megabytes e é sub-milissegundo, e exato significa que o ranking nunca erra em silêncio
  por recall. Com as 2,4 mil linhas dos temas o scan continua em milissegundos. O índice
  passa a valer quando Jurisprudência trouxer a própria tabela.

```bash
php artisan lexia:embed-procedural-classes           # o que estiver desatualizado
php artisan lexia:embed-procedural-classes --fresh   # tudo, ignorando o hash
php artisan lexia:import-legal-themes                # baixa, importa e embute o que mudou
```

Não é obrigatório rodar: `SelectProceduralClass` embute as candidatas que faltarem antes
de ranquear, então um `migrate:fresh` seguido de `composer test:agents` funciona sem
passo manual — só paga a inferência na primeira classificação de cada área.

Atenção: o cast `AsVector` que a documentação do SDK mostra **não existe** no
`laravel/framework` 12.69.2. O nosso está em `App\Domain\Shared\Casts\AsVector`.

## O acoplamento do guia de classes com o prompt

`procedural-classes.md` guarda os **desempates** — os pares que de fato se confundem e a
ordem de decisão (pedido antes do acontecimento, título antes de rito, rito próprio antes
do comum). Isso não é redundância com o catálogo: o catálogo diz o que cada classe é, o
guia diz quando *não* escolhê-la.

O guia também descreve o tronco cível, porque uma classe do tronco pode ficar fora do
orçamento de descrição e chegar ao prompt só pelo nome.

Consequência: se a lista de genéricas mudar numa ressincronização do CNJ, o documento
muda junto. `ProceduralClassCandidatesTest` verifica que todo código citado aqui ainda
existe no catálogo.

## Escrevendo um documento novo

Escreva para ser lido por um modelo e conferido por um advogado. O que faz diferença na
qualidade da resposta, em ordem:

1. **Os desempates**, mais que as definições. "Não confundir com X quando Y" resolve mais
   casos do que descrever bem cada área isolada.
2. **O identificador exato** que a resposta deve usar, escrito junto de cada item.
3. **As saídas fáceis marcadas como tal.** Os rótulos mais neutros do catálogo atraem o
   relato ambíguo; dizer explicitamente quando *não* escolhê-los é o que protege a
   classificação.
