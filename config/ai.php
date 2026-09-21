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
    | Gemini had bought off — latency. It is why every agent carries
    | `#[Timeout(360)]` rather than the 180 the cloud was comfortable with: a
    | 20B model answering on the office machine is slower than a flash model
    | answering from a datacentre, and a timeout tuned for the second one turns
    | a working local inference into an exception. `POST /pecas/classificar`
    | runs its first four steps concurrently and then waits on the research,
    | which is the debt the route's `asController()` documents and a queue is
    | the way out of. What it buys back: the client's narrative leaves the
    | office only for the thesis research, and there is almost no quota to pay.
    |
    | Embeddings never left. The catalogue is 615 rows already vectorised with
    | `nomic-embed-text`, a vector from one model is not comparable with a
    | vector from another, and it is the width `procedural_classes.embedding`
    | was declared with — and the width the Jurisprudência corpus will be
    | declared with.
    |
    | The Gemini block below is no longer commented out, and that is not the
    | move back to the cloud: `AI_PROVIDER` says `ollama`, and **one** agent
    | asks otherwise. LegalThesisResearchAgent searches the official portals, so
    | it needs provider-side web tools, and Ollama does not merely lack them —
    | `OllamaGateway::mapTools()` throws a RuntimeException on the first
    | `ProviderTool` it sees. That agent has no local option and the attribute
    | says so; every other one, including the transcriber that reads its sheet,
    | answers from the daemon.
    |
    | Moving the *text* back to the cloud wholesale is still the same two
    | gestures it always was: put `AI_PROVIDER=gemini` in the `.env` and swap
    | the `#[Provider('ollama')]` of the seven local agents for the line
    | commented above each one.
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
    | `ConfiguresOllamaRuntime` emits `num_ctx`, which is Ollama's spelling,
    | so the number now reaches the wire. It matters because Ollama truncates an
    | overrunning prompt in silence, leaving an answer that still reads
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
    | `App\Ai\Concerns\ConfiguresOllamaRuntime` ao lado do `num_ctx` acima.
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
    | Quem aplica é `App\Http\Middleware\AllowLongInference`, e só nas quatro
    | rotas que de fato esperam por uma inferência. Ele nunca abaixa um limite:
    | zero é ilimitado, que é o que a CLI e o `artisan serve` entregam.
    |
    | 900 s é o pior caso com folga. A pesquisa de teses são **dois** agentes em
    | série — o do Gemini abrindo os portais e o transcritor local —, cada um
    | com 360 s de teto, mais o que a gravação custa. As demais rotas cabem
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
        | Ativo, e por um agente só. `AI_PROVIDER` continua `ollama`: sete dos
        | oito seguem na máquina, e quem aponta para cá é o
        | `#[Provider('gemini')]` de LegalThesisResearchAgent.
        |
        | Ele não tem escolha: pesquisar exige buscar e ler página na web, que
        | no pacote são ferramentas do lado do provedor, e o gateway do Ollama
        | lança exceção ao ver a primeira delas. Consequência de fronteira: o
        | relato do cliente só sai do escritório na pesquisa de teses, e ali
        | `LegalCaseDossier::forResearch()` já corta o cliente e o réu inteiros.
        |
        | A chave `models` não é enfeite: sem ela o GeminiProvider cai num
        | padrão que muda com a versão do pacote. Não há `embeddings` de
        | propósito — quem embute é o Ollama abaixo. Se uma entrada de
        | embeddings voltar, `dimensions` chega ao Gemini como
        | `outputDimensionality` e precisa valer 768, ou a coluna vetorial
        | precisa de migration no mesmo fôlego.
        |
        | A armadilha desta escolha, medida no código do pacote e registrada
        | por extenso no agente: `GeminiProvider::webSearchToolOptions()` e
        | `webFetchToolOptions()` devolvem `[]`, então o `->allow([...])` que
        | restringe a busca aos portais oficiais é descartado antes de virar
        | requisição — e não há escape hatch, porque este provider também não
        | lê o `providerOptions()` da ferramenta. A lista de domínios não é
        | imposta aqui. Quem a impõe é LegalResearchData, na volta.
        */

        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
            'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/'),

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
                | Em uso pelos sete agentes locais. O `gpt-oss:20b` raciocina, e é
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
