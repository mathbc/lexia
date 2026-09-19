<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | Split down the middle, and the line is drawn where it costs least.
    |
    | Text goes to Gemini: the five agents read a client's narrative and answer
    | in seconds instead of minutes, which is what `POST /pecas/classificar`
    | needs while it is still four inferences with the browser waiting.
    |
    | Embeddings stay on the local Ollama. There was nothing to gain by moving
    | them — the catalogue is 615 rows already vectorised with
    | `nomic-embed-text`, and a vector from one model is not comparable with a
    | vector from another, so the move would have cost a full re-embed to buy
    | nothing. Keeping them here also keeps `nomic-embed-text` as the width the
    | Jurisprudência corpus will be declared with.
    |
    | The Ollama text models are still configured below, unused, so that
    | putting `AI_PROVIDER=ollama` in the `.env` and uncommenting one line per
    | agent takes the narrative back off the network entirely.
    |
    | The keys for images, audio, transcription and reranking are deliberately
    | absent — the published default points each of them at a provider that
    | needs an API key we do not have, and a default nobody can honour is worse
    | than no default at all. Add one back when a feature actually needs it.
    |
    */

    'default' => env('AI_PROVIDER', 'gemini'),
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
    | With text on Gemini the number is currently documentation rather than a
    | setting: `UsesConfiguredContextWindow` only emits `num_ctx`, which is
    | Ollama's spelling, so today it sends nothing. It stops being documentation
    | the moment the agents point back at Ollama — which truncates an
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
    | O teto sobreviveu à ida do texto para o Gemini de propósito. A janela de
    | lá comportaria o catálogo inteiro, mas o corte nunca foi só sobre caber:
    | descrever 54 classes para escolher uma dilui a atenção, e agora também se
    | paga por token. Suba se o modelo errar por não ter visto a classe certa
    | descrita.
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
    | Two providers, one job each, and every other one removed: failover walks
    | this list, so a provider left here without credentials is just a slower
    | way to fail. Neither of these is a fallback for the other — `default`
    | picks the one that answers in prose, `default_for_embeddings` the one that
    | answers in vectors.
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
        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
            'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/'),

            /*
            | Sem a chave `models` o GeminiProvider ainda acharia um padrão,
            | mas um que muda com a versão do pacote. Nomear aqui é o que faz
            | a troca de modelo ser uma linha de `.env`.
            |
            | Não há `embeddings`: quem embute é o Ollama, logo abaixo. Se um
            | dia esta entrada voltar, `dimensions` chega ao Gemini como
            | `outputDimensionality` e precisa valer 768, ou a coluna vetorial
            | precisa de migration no mesmo fôlego.
            */
            'models' => [
                'text' => [
                    'default' => env('GEMINI_TEXT_MODEL', 'gemini-3.6-flash'),
                    'cheapest' => env('GEMINI_TEXT_MODEL', 'gemini-3.6-flash'),
                    'smartest' => env('GEMINI_TEXT_MODEL', 'gemini-3.6-flash'),
                ],
            ],
        ],

        'ollama' => [
            'driver' => 'ollama',
            'key' => env('OLLAMA_API_KEY', ''),
            'url' => env('OLLAMA_URL', 'http://localhost:11434'),

            'models' => [
                /*
                | Ocioso enquanto `default` apontar para o Gemini, e mantido
                | justamente por isso: com o bloco aqui, voltar a inferência
                | para a máquina do escritório é `AI_PROVIDER=ollama` mais o
                | `#[Provider('ollama')]` comentado acima de cada um dos cinco
                | agentes. Sem ele, o OllamaProvider cairia em `qwen3.5:4b`,
                | que não é um modelo que este projeto baixe.
                */
                'text' => [
                    'default' => env('OLLAMA_TEXT_MODEL', 'qwen2.5:7b'),
                    'cheapest' => env('OLLAMA_TEXT_MODEL', 'qwen2.5:7b'),
                    'smartest' => env('OLLAMA_TEXT_MODEL', 'qwen2.5:7b'),
                ],

                // Este, sim, está em uso: é o que vetoriza o catálogo.
                'embeddings' => [
                    'default' => env('OLLAMA_EMBEDDINGS_MODEL', 'nomic-embed-text'),
                    'dimensions' => 768,
                ],
            ],
        ],
    ],

];
