<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | Everything local again: text and embeddings both answer from the Ollama
    | daemon on the office machine.
    |
    | Text came back with `gpt-oss:20b`, and the price is the one the move to
    | Gemini had bought off — latency. `POST /pecas/classificar` is four
    | inferences in series with the browser waiting, which is the debt the
    | route's `asController()` documents and a queue is the way out of. What it
    | buys back: the client's narrative never leaves the office, and there is no
    | quota to pay.
    |
    | Embeddings never left. The catalogue is 615 rows already vectorised with
    | `nomic-embed-text`, a vector from one model is not comparable with a
    | vector from another, and it is the width `procedural_classes.embedding`
    | was declared with — and the width the Jurisprudência corpus will be
    | declared with.
    |
    | The Gemini block below is kept, commented out, so the move back to the
    | cloud is three gestures: uncomment it, put `AI_PROVIDER=gemini` in the
    | `.env`, and swap the `#[Provider('ollama')]` of the five agents for the
    | line commented above each one.
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
    | How much context an agent's prompt is allowed to fill.
    |
    | It is a property of the prompts this project writes, not of whichever
    | model is answering them, so it is stated once here instead of per agent.
    | Raise it if a prompt grows; claiming more than needed only costs memory.
    |
    | Measured, not guessed: the largest system prompt the class agent builds —
    | Penal, 45 candidates, 31 of them described — is 10,946 tokens by Ollama's
    | own `prompt_eval_count`. Add the schema the gateway appends, a long client
    | narrative and the answer, and 24k leaves real headroom where 16k left
    | almost none.
    |
    | With text back on Ollama this is a setting again rather than documentation:
    | `UsesConfiguredContextWindow` emits `num_ctx`, which is Ollama's spelling,
    | so the number now reaches the wire. It matters because Ollama truncates an
    | overrunning prompt in silence, leaving an answer that still reads
    | plausible on top of a system prompt that lost its tail.
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
    | Com o texto de volta no Ollama o teto volta a ser também restrição física:
    | 16 KB de descrições mais o guia de conhecimento têm de caber nos 24k de
    | `context_window` acima, e não há janela de nuvem para absorver o excesso.
    | Continua valendo o motivo original — descrever 54 classes para escolher
    | uma dilui a atenção. Suba se o modelo errar por não ter visto a classe
    | certa descrita, mas suba `AI_CONTEXT_WINDOW` junto.
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
    | One provider, both jobs, and every other one removed: failover walks this
    | list, so a provider left here without credentials is just a slower way to
    | fail. `default` picks who answers in prose and `default_for_embeddings`
    | who answers in vectors — hoje é o mesmo daemon respondendo às duas
    | perguntas com modelos diferentes.
    |
    | The `models` key is not decoration, and it is the *only* place a text
    | model is named: no agent carries a `#[Model]` attribute, so every one of
    | them resolves to its provider's `text.default` and a single env var swaps
    | the model for the whole application. The three text entries all point at
    | the same model because there is only one, and the SDK asks for a cheapest
    | and a smartest by name.
    |
    | 768 is what `nomic-embed-text` returns; it is the width
    | `procedural_classes.embedding` was declared with, and the width the
    | vector columns of the Jurisprudência module will be declared with.
    |
    */

    'providers' => [

        /*
        | Comentado enquanto o texto for local. Descomentar este bloco é o
        | primeiro dos três gestos que devolvem a inferência ao Gemini — os
        | outros dois são `AI_PROVIDER=gemini` no `.env` e o `#[Provider]` dos
        | cinco agentes. Fica aqui, e não no histórico do git, porque o que
        | custa a lembrar não é o driver: é que sem a chave `models` o
        | GeminiProvider acha um padrão que muda com a versão do pacote, e que
        | não há `embeddings` de propósito — quem embute é o Ollama abaixo. Se
        | uma entrada de embeddings voltar, `dimensions` chega ao Gemini como
        | `outputDimensionality` e precisa valer 768, ou a coluna vetorial
        | precisa de migration no mesmo fôlego.
        |
        | 'gemini' => [
        |     'driver' => 'gemini',
        |     'key' => env('GEMINI_API_KEY'),
        |     'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/'),
        |
        |     'models' => [
        |         'text' => [
        |             'default' => env('GEMINI_TEXT_MODEL', 'gemini-3.6-flash'),
        |             'cheapest' => env('GEMINI_TEXT_MODEL', 'gemini-3.6-flash'),
        |             'smartest' => env('GEMINI_TEXT_MODEL', 'gemini-3.6-flash'),
        |         ],
        |     ],
        | ],
        */

        'ollama' => [
            'driver' => 'ollama',
            'key' => env('OLLAMA_API_KEY', ''),
            'url' => env('OLLAMA_URL', 'http://localhost:11434'),

            'models' => [
                /*
                | Em uso pelos cinco agentes. O `gpt-oss:20b` raciocina, e é
                | exatamente o modelo que respondia com conteúdo vazio quando
                | lhe mandavam `think: false` — a armadilha que o
                | `providerOptions()` dos agentes não deve reintroduzir. Com o
                | thinking ligado (o padrão) o Ollama separa o raciocínio em
                | `message.thinking` e o JSON chega limpo.
                |
                | Nomear aqui é o que faz a troca de modelo ser uma linha de
                | `.env`: sem este bloco o OllamaProvider cairia em `qwen3.5:4b`,
                | que não é um modelo que este projeto baixe.
                */
                'text' => [
                    'default' => env('OLLAMA_TEXT_MODEL', 'gpt-oss:20b'),
                    'cheapest' => env('OLLAMA_TEXT_MODEL', 'gpt-oss:20b'),
                    'smartest' => env('OLLAMA_TEXT_MODEL', 'gpt-oss:20b'),
                ],

                // É o que vetoriza o catálogo, e não mudou com a volta do texto.
                'embeddings' => [
                    'default' => env('OLLAMA_EMBEDDINGS_MODEL', 'nomic-embed-text'),
                    'dimensions' => 768,
                ],
            ],
        ],
    ],

];
