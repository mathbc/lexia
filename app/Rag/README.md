# RAG

Reservado para o pipeline de Retrieval-Augmented Generation do módulo de
Jurisprudência: ingestão, chunking, embedding e recuperação.

Ainda vazio por decisão de escopo. Quando for implementado, seguir a
documentação oficial do Laravel AI (https://laravel.com/ai):

- Embeddings via Ollama com `nomic-embed-text` (768 dimensões, que é o default
  do provider Ollama no `laravel/ai`).
- Busca vetorial com os helpers nativos do core do Laravel 12
  (`Schema::ensureVectorExtensionExists()`, `$table->vector('embedding', 768)`,
  `whereVectorSimilarTo()`), sem pacote adicional de pgvector.
