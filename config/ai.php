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
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | One provider, and every other one removed: failover walks this list, so
    | a provider left here without credentials is just a slower way to fail.
    |
    | The `models` key is not decoration. Without it OllamaProvider falls back
    | to `qwen3.5:4b`, which is not a model this project pulls — the three text
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
                    'default' => env('OLLAMA_TEXT_MODEL', 'gpt-oss:20b'),
                    'cheapest' => env('OLLAMA_TEXT_MODEL', 'gpt-oss:20b'),
                    'smartest' => env('OLLAMA_TEXT_MODEL', 'gpt-oss:20b'),
                ],

                'embeddings' => [
                    'default' => env('OLLAMA_EMBEDDINGS_MODEL', 'nomic-embed-text'),
                    'dimensions' => 768,
                ],
            ],
        ],
    ],

];
