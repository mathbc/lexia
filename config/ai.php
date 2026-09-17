<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | Ollama for everything, because everything runs on the lawyer's own
    | infrastructure: a pleading carries the client's story, and that story
    | does not leave the building to be classified.
    |
    | The keys for images, audio, transcription and reranking are deliberately
    | absent — the published default points each of them at a provider that
    | needs an API key we do not have, and a default nobody can honour is worse
    | than no default at all. Add one back when a feature actually needs it.
    |
    */

    'default' => env('AI_PROVIDER', 'ollama'),
    'default_for_embeddings' => env('AI_EMBEDDINGS_PROVIDER', 'ollama'),

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Off while the only embeddings we generate are smoke tests. Turn it on
    | when the Jurisprudência ingestion starts re-embedding the same corpus.
    |
    */

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
            'individually' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Context Window
    |--------------------------------------------------------------------------
    |
    | How much context an agent's prompt is allowed to fill. Ollama truncates a
    | prompt that overruns the window in silence — the answer still comes back
    | plausible, built on a system prompt that lost its tail — and left unset
    | the daemon decides it. Our prompts are large: the areas guide alone is
    | 19 KB and the candidate list reaches 18 KB.
    |
    | It is a property of the prompts this project writes, not of whichever
    | model is answering them, so it is stated once here instead of per agent.
    | Raise it if a prompt grows; claiming more than needed only costs memory.
    |
    | Measured, not guessed: the largest system prompt the class agent builds —
    | Penal, 45 candidates, 31 of them described — is 10,946 tokens by Ollama's
    | own `prompt_eval_count`. Add the schema the gateway appends, a long client
    | narrative and the answer, and 24k leaves real headroom where 16k left
    | almost none. qwen2.5:7b carries 32k natively, so this still fits.
    |
    */

    'context_window' => (int) env('AI_CONTEXT_WINDOW', 24576),

    /*
    |--------------------------------------------------------------------------
    | Retrieval
    |--------------------------------------------------------------------------
    |
    | Quanto da janela a lista de classes candidatas pode gastar descrevendo —
    | descrição, matérias típicas e artigos de base. As que não couberem chegam
    | só com nome e código; todas continuam no `enum`, então isto é orçamento de
    | prompt, nunca restrição das respostas possíveis.
    |
    | Em caracteres, e não em número de classes, porque é o tamanho que é o
    | limite de verdade: 17 das 24 áreas cabem inteiras em 16 KB e não perdem
    | nada, enquanto Família (54 candidatas, 33 KB) precisaria ser cortada de
    | qualquer forma. Um teto por contagem cortaria as duas do mesmo jeito.
    |
    | A ordem do corte é a da proximidade vetorial com o relato
    | (ProceduralClassRankingQuery). O piso garante um mínimo de classes
    | descritas mesmo que as primeiras sejam anormalmente longas.
    |
    | Medido: com 16 KB de lista, o maior prompt de sistema — Penal — fica em
    | 10.946 tokens dos 24576 de `context_window`. Suba se o modelo errar por não
    | ter visto a classe certa descrita; desça se o Ollama começar a truncar.
    |
    */

    'retrieval' => [
        'description_budget' => (int) env('AI_DESCRIPTION_BUDGET', 16000),
        'minimum_described' => (int) env('AI_MINIMUM_DESCRIBED', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | One provider, and every other one removed: failover walks this list, so
    | a provider left here without credentials is just a slower way to fail.
    |
    | The `models` key is not decoration, and it is the *only* place a text
    | model is named: no agent carries a `#[Model]` attribute, so every one of
    | them resolves to `text.default` and a single env var swaps the model for
    | the whole application. Without this key OllamaProvider falls back to
    | `qwen3.5:4b`, which is not a model this project pulls — the three text
    | entries all point at the same model because there is only one, and the
    | SDK asks for a cheapest and a smartest by name.
    |
    | 768 is what `nomic-embed-text` returns; it is also the width the vector
    | columns of the Jurisprudência module will be declared with.
    |
    */

    'providers' => [
        'ollama' => [
            'driver' => 'ollama',
            'key' => env('OLLAMA_API_KEY', ''),
            'url' => env('OLLAMA_URL', 'http://localhost:11434'),

            'models' => [
                'text' => [
                    'default' => env('OLLAMA_TEXT_MODEL', 'qwen2.5:7b'),
                    'cheapest' => env('OLLAMA_TEXT_MODEL', 'qwen2.5:7b'),
                    'smartest' => env('OLLAMA_TEXT_MODEL', 'qwen2.5:7b'),
                ],

                'embeddings' => [
                    'default' => env('OLLAMA_EMBEDDINGS_MODEL', 'nomic-embed-text'),
                    'dimensions' => 768,
                ],
            ],
        ],
    ],

];
