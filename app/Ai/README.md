# AI

Pasta de convenção do `laravel/ai`: `php artisan make:agent` e `php artisan make:tool`
geram em `app/Ai/Agents` e `app/Ai/Tools`. O conhecimento que os agentes leem fica em
`app/Rag`.

## Agentes

| Agente | O que faz |
|---|---|
| `PracticeAreaClassificationAgent` | Lê a descrição dos fatos e devolve a área de atuação, com justificativa |
| `ProceduralClassSelectionAgent` | Dentro da área já decidida, escolhe a classe processual do CNJ, com justificativa |

Um agente não é chamado direto da tela: quem o expõe é uma Action do domínio
(`ClassifyPracticeArea`, `SelectProceduralClass`), que carrega o contexto, resolve a
resposta em modelo e é o ponto por onde o caso de uso entra. `ClassifyLegalCase`
encadeia os dois.

## Por que dois agentes, e não um com ferramenta

As classes que uma peça pode receber são as vinculadas à área dela — então a lista de
respostas permitidas só existe **depois** que a área foi decidida. Uma ferramenta que
buscasse as classes no meio de uma única conversa daria ao modelo a mesma informação,
mas devolveria a resposta ao terreno da boa vontade: o código da classe deixaria de ser
restringido por gramática e passaria a ser validado depois do fato. Duas chamadas, cada
uma com seu `enum`, custam uma inferência a mais e compram a garantia.

O preço é latência: dois `Timeout(180)` em série não cabem num request síncrono. A tela
que pedir isso vai despachar, não esperar.

## Contexto: `AI_CONTEXT_WINDOW`, e por que ele é explícito

Sem `num_ctx` o tamanho de contexto é o default do daemon, e o Ollama **trunca em
silêncio** quando o prompt estoura — a resposta volta plausível, construída sobre um
prompt que perdeu o fim. O guia de áreas sozinho tem 19 KB; a lista de classes chega
a 18 KB.

O número é **um só, e não é do modelo**: `ai.context_window` (env `AI_CONTEXT_WINDOW`,
16384 por padrão) descreve o tamanho dos prompts que este projeto escreve, e quem o
entrega ao provider é o trait `App\Ai\Concerns\UsesConfiguredContextWindow`. Um agente
novo usa o trait e implementa `HasProviderOptions`; nada nele precisa saber qual modelo
está respondendo. O trait só emite `num_ctx` para o driver `ollama`, que é quem conhece
essa chave.

Cuidado ao mexer: `providerOptions` cai na chave `options` do corpo, mas `think`,
`format` e `keep_alive` são içados para o topo — não coloque `think` ali.

## Provider e modelo

Ollama local, configurado em `config/ai.php`: `qwen3.8:27b` para texto e
`nomic-embed-text` (768 dimensões) para embeddings. É o único provider declarado —
a história do cliente não sai da infraestrutura do escritório para ser classificada.

**Nenhum agente carrega `#[Model]`.** O modelo é nomeado em um lugar só,
`ai.providers.ollama.models.text` (env `OLLAMA_TEXT_MODEL`), e sem o atributo o SDK
resolve cada agente por `defaultTextModel()`. Trocar de modelo é editar o `.env` e
rodar `php artisan config:clear`.

## Três armadilhas, nenhuma exclusiva de um modelo

1. **Nunca mande `think: false`.** O `gpt-oss:20b` respondia com `content` vazio, e a
   linha qwen3 também raciocina por padrão. Deixado em paz, o Ollama separa o raciocínio
   em `message.thinking` e o JSON chega limpo em `message.content`, que é o que a saída
   estruturada consome.
2. **A chave `models` no `config/ai.php` é obrigatória.** Sem ela o `OllamaProvider` cai
   no default do pacote, `qwen3.5:4b`, que este projeto não baixa.
3. **O modelo precisa suportar `tools`/saída estruturada.** O `format` do Ollama vira
   gramática; um modelo sem essa capacidade devolve JSON só por boa vontade. Confira com
   `ollama show <modelo>` antes de trocar.

## Saída estruturada

Um agente que implementa `HasStructuredOutput` tem seu `schema()` convertido em gramática
pelo Ollama. `enum()` num campo string é o que impede o modelo de inventar um valor — é
assim que a classificação de área garante devolver um dos 24 slugs do catálogo, e não uma
área plausível que não existe.
