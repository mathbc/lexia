<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | Not where the platform's models are chosen any more. Which text model the
    | agents run on and which embedding model vectorizes the catalogues are rows
    | flagged `default` in `ai_models` and `embedding_models`, kept by LexIA
    | staff in /modelos-de-ia and /modelos-de-embedding and seeded by
    | `php artisan lexia:install`. Every agent answers `provider()` and
    | `model()` from that row (UsesDefaultAiModel), and DocumentEmbedder passes
    | provider, model and dimensions on every embedding request — so neither
    | key below is ever reached by the application.
    |
    | They stay, pointed at Gemini, because the package merges its own config
    | shallowly: a key removed here comes back from the vendor file, which
    | points embeddings at OpenAI, a provider this project has no key for.
    |
    | Every agent carries `#[Timeout(360)]`, sized for the slowest option the
    | catalogue can hold — a 20B model answering from the office daemon — and
    | not for a flash model answering from a datacentre: a timeout tuned for
    | the second turns a working local inference into an exception.
    |
    | The keys for images, audio, transcription and reranking are deliberately
    | absent — the published default points each of them at a provider that
    | needs an API key we do not have, and a default nobody can honour is worse
    | than no default at all. Add one back when a feature actually needs it.
    |
    */

    'default' => 'gemini',
    'default_for_embeddings' => 'gemini',

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
    | It only reaches the wire when the default model is an Ollama one:
    | `ConfiguresOllamaRuntime` emits it as `num_ctx`, Ollama's spelling, and
    | nothing for a cloud provider. It matters there because Ollama truncates
    | an overrunning prompt in silence, leaving an answer that still reads
    | plausible on top of a system prompt that lost its tail.
    |
    */

    'context_window' => (int) env('AI_CONTEXT_WINDOW', 24576),

    /*
    |--------------------------------------------------------------------------
    | Ollama Runtime
    |--------------------------------------------------------------------------
    |
    | Duas grafias que só o Ollama entende, emitidas por
    | `App\Ai\Concerns\ConfiguresOllamaRuntime` ao lado do `num_ctx` acima —
    | e só quando o modelo padrão é do Ollama.
    |
    | `keep_alive` é quanto tempo o modelo fica residente. O default do daemon é
    | cinco minutos, e toda pausa maior cobra da requisição seguinte a recarga
    | de um modelo de 12,8 GB — latência pura, em desenvolvimento e em qualquer
    | produção de tráfego esparso.
    |
    | `reasoning_effort` é a maior alavanca de performance do projeto, porque no
    | caminho local o decode é ~99% do tempo e o raciocínio é quase todo ele.
    | Medido no `gpt-oss:20b` com o prompt real do agente de pedidos: o padrão
    | gastou 42.239 caracteres de raciocínio em 156 s e devolveu lista **vazia**,
    | enquanto `low` respondeu em 5,6 s com três pedidos. Numa segunda amostra o
    | padrão levou 29 s e devolveu quatro. Mais raciocínio estava comprando
    | variância, não qualidade.
    |
    | Atenção: isto NÃO é a armadilha do `think: false`, que devolve conteúdo
    | vazio. `low` mantém o raciocínio ligado e curto, e o daemon continua
    | separando-o em `message.thinking`, que o gateway ignora.
    |
    | Este é o padrão de quem lê e escolhe. Quem compõe prosa — o refinamento de
    | fatos e a minuta — sobrescreve `reasoningEffort()` e devolve null, porque
    | ali a deliberação extra paga os segundos que custa.
    |
    */

    'runtime' => [
        'keep_alive' => env('OLLAMA_KEEP_ALIVE', '30m'),
        'reasoning_effort' => env('OLLAMA_REASONING_EFFORT', 'low'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Time Limit
    |--------------------------------------------------------------------------
    |
    | Quanto tempo o PHP pode gastar num request que espera por um agente.
    |
    | Não confundir com o `#[Timeout(360)]` dos agentes: aquele é a paciência do
    | cliente HTTP falando com o provedor, este é a paciência do interpretador
    | com o próprio script. São independentes, e o segundo vence — um `php.ini`
    | de fábrica traz `max_execution_time = 30`, e a requisição morre em 32 s
    | com `Maximum execution time of 30+2 seconds exceeded (terminated)`,
    | apontando para o cURL do Guzzle. O `+2` é o timeout duro: o normal não
    | consegue interromper um `curl_exec()` bloqueado, então o processo é
    | abatido em vez de lançar exceção — nada é gravado e nada é capturado.
    |
    | Quem aplica é `App\Http\Middleware\AllowLongInference`, e só nas rotas
    | que de fato esperam por uma inferência. Ele nunca abaixa um limite:
    | zero é ilimitado, que é o que a CLI e o `artisan serve` entregam.
    |
    | 900 s é o pior caso com folga. A pesquisa de teses são **dois** agentes em
    | série — o pesquisador abrindo os portais e o transcritor —, cada um com
    | 360 s de teto, mais o que a gravação custa. As demais rotas cabem
    | numa fração disso e usam o mesmo número por não haver motivo para
    | distingui-las.
    |
    | Isto não alcança `fastcgi_read_timeout` nem `request_terminate_timeout`:
    | num servidor de verdade quem corta é o proxy, e a saída é a fila que
    | ResearchLegalCaseForensicReview registra como dívida.
    |
    */

    'request_time_limit' => (int) env('AI_REQUEST_TIME_LIMIT', 900),

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
    | Com um modelo padrão do Ollama o teto é também restrição física: 16 KB de
    | descrições mais o guia de conhecimento têm de caber nos 24k de
    | `context_window` acima, e não há janela de nuvem para absorver o excesso.
    | Continua valendo o motivo original — descrever 54 classes para escolher
    | uma dilui a atenção. Suba se o modelo errar por não ter visto a classe
    | certa descrita, mas suba `AI_CONTEXT_WINDOW` junto.
    |
    | Os temas do STJ são o regime oposto, e `theme_candidates` é top-k de
    | verdade: dos 2,4 mil temas, só os mais próximos das questões de direito
    | do caso chegam ao `LegalThemeSelectionAgent`, e os demais não são resposta
    | possível. Lá não há `enum` fixo a proteger — a pergunta é "quais pesam",
    | não "qual destes" —, então o corte é por contagem. São vinte porque a
    | busca é por questão (de três a oito, intercaladas): com oito questões,
    | vinte ainda dão a cada uma os seus dois ou três temas mais próximos. Um
    | tema descrito custa em média ~450 caracteres (questão, tese e
    | delimitação, medido no catálogo), então vinte cabem com folga; suba se o
    | agente não estiver vendo o tema certo.
    |
    */

    'retrieval' => [
        'description_budget' => (int) env('AI_DESCRIPTION_BUDGET', 16000),
        'minimum_described' => (int) env('AI_MINIMUM_DESCRIBED', 10),
        'theme_candidates' => (int) env('AI_THEME_CANDIDATES', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Only how to reach each provider: the driver and the URL. Which model runs
    | is the database's (see the top of this file), so there is no `models`
    | key — the SDK would only read it for a model nobody named, and every
    | request here names one. And there is no `key` either: the API key is the
    | model row's (`api_key`, encrypted), typed in /modelos-de-ia and
    | /modelos-de-embedding.
    |
    | Each block's name is an `App\Domain\AiModels\Enums\AiProvider` value, and
    | it is a template: ProviderInstance copies the block of a model's provider,
    | adds the row's key and registers the result under a name of its own, which
    | is what the agents and DocumentEmbedder hand the SDK. A case in the enum
    | without a block here is a model nothing can call.
    |
    | Every other provider is removed: failover walks this list, so a provider
    | left here without credentials is just a slower way to fail.
    |
    */

    'providers' => [

        /*
        | O único que pesquisa: buscar e ler página na web são ferramentas do
        | lado do provedor, e o gateway do Ollama lança exceção ao ver a
        | primeira delas. Por isso os três agentes de pesquisa exigem um
        | modelo padrão daqui (UsesDefaultWebSearchModel).
        |
        | Os embeddings chegam com `outputDimensionality` 768, a largura das
        | colunas vetoriais, e com o `taskType` da tarefa (DocumentEmbedder).
        |
        | A armadilha desta escolha, medida no código do pacote e registrada
        | por extenso no LegalThesisResearchAgent:
        | `GeminiProvider::webSearchToolOptions()` e `webFetchToolOptions()`
        | devolvem `[]`, então o `->allow([...])` que restringe a busca aos
        | portais oficiais é descartado antes de virar requisição — e não há
        | escape hatch, porque este provider também não lê o
        | `providerOptions()` da ferramenta. A lista de domínios não é imposta
        | aqui. Quem a impõe é LegalResearchData, na volta.
        */

        'gemini' => [
            'driver' => 'gemini',
            'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/'),
        ],

        /*
        | O daemon local, para um modelo padrão do Ollama — em desenvolvimento,
        | ou numa máquina do escritório. O `gpt-oss:20b` raciocina, e é
        | exatamente o modelo que respondia com conteúdo vazio quando lhe
        | mandavam `think: false` — a armadilha que o `providerOptions()` dos
        | agentes não deve reintroduzir. Com o thinking ligado (o padrão) o
        | Ollama separa o raciocínio em `message.thinking` e o JSON chega limpo.
        */

        'ollama' => [
            'driver' => 'ollama',
            'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        ],
    ],

];
