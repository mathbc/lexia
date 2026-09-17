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
    | Raise it if a prompt grows; every model this project would run clears 16k
    | comfortably, and claiming more than needed only costs memory.
    |
    */

    'context_window' => (int) env('AI_CONTEXT_WINDOW', 16384),

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
                    'default' => env('OLLAMA_TEXT_MODEL', 'qwen3.8:27b'),
                    'cheapest' => env('OLLAMA_TEXT_MODEL', 'qwen3.8:27b'),
                    'smartest' => env('OLLAMA_TEXT_MODEL', 'qwen3.8:27b'),
                ],

                'embeddings' => [
                    'default' => env('OLLAMA_EMBEDDINGS_MODEL', 'nomic-embed-text'),
                    'dimensions' => 768,
                ],
            ],
        ],
    ],

];
