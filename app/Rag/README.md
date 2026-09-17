# RAG

O conhecimento que os agentes de `app/Ai` leem. Markdown em `knowledge/`, carregado por
`KnowledgeBase`.

| Documento | Usado por |
|---|---|
| `knowledge/practice-areas.md` | `PracticeAreaClassificationAgent` |
| `knowledge/procedural-classes.md` | `ProceduralClassSelectionAgent` |

## Por que o documento inteiro, e não os top-k trechos

`KnowledgeBase::get()` devolve o arquivo completo. Para a classificação de área isso é
melhor que recuperação por similaridade, não uma simplificação: o guia descreve 24 opções
mutuamente exclusivas em poucos kilobytes, e um corte top-k só poderia **remover**
candidatos — justamente quando o relato é vago, que é o caso que mais precisa de todas as
opções na mesa.

Recuperação vetorial vale contra um corpus que não cabe inteiro no prompt, que é o que o
módulo de Jurisprudência vai ser.

## Quando a etapa vetorial entrar

- Embeddings via Ollama com `nomic-embed-text` — já integrado e verificado (768
  dimensões, `Laravel\Ai\Embeddings::for([...])->generate()`).
- Coluna com os helpers nativos do core do Laravel 12
  (`Schema::ensureVectorExtensionExists()`, `$table->vector('embedding', 768)`,
  `whereVectorSimilarTo()`), sem pacote adicional de pgvector.
- A ferramenta de busca do próprio SDK é `Laravel\Ai\Tools\SimilaritySearch`, que um
  agente publica no `tools()`.

Atenção: o cast `AsVector` que a documentação oficial mostra **não existe** no
`laravel/framework` 12.69.2. Até existir, a hidratação da coluna precisa de cast próprio.

## O acoplamento do guia de classes com o prompt

`procedural-classes.md` descreve o **tronco cível** — as 24 classes genéricas que
aparecem em quase toda área. Isso não é redundância com o catálogo: o prompt manda essas
classes **só pelo nome**, justamente porque elas estão descritas aqui. Descrever as 24 em
todo prompt custaria ~7 KB na maior área e diria menos do que o guia diz, que é quando
cada uma cabe e quando não cabe.

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
