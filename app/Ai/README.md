# AI

Pasta de convenção do `laravel/ai`: `php artisan make:agent` e `php artisan make:tool`
geram em `app/Ai/Agents` e `app/Ai/Tools`. O conhecimento que os agentes leem fica em
`app/Rag`.

## Agentes

| Agente | O que faz |
|---|---|
| `PracticeAreaClassificationAgent` | Lê a descrição dos fatos e devolve a área de atuação, com justificativa |

Um agente não é chamado direto da tela: quem o expõe é uma Action do domínio
(`ClassifyPracticeArea`), que carrega o contexto, resolve a resposta em modelo e é o
ponto por onde o caso de uso entra.

## Provider

Ollama local, configurado em `config/ai.php`: `gpt-oss:20b` para texto e
`nomic-embed-text` (768 dimensões) para embeddings. É o único provider declarado —
a história do cliente não sai da infraestrutura do escritório para ser classificada.

## Duas armadilhas do gpt-oss:20b

1. **Nunca mande `think: false`.** O modelo responde com `content` vazio. Deixado em paz,
   o Ollama separa o raciocínio em `message.thinking` e o JSON chega limpo em
   `message.content`, que é o que a saída estruturada consome.
2. **A chave `models` no `config/ai.php` é obrigatória.** Sem ela o `OllamaProvider` cai
   no default do pacote, `qwen3.5:4b`, que este projeto não baixa.

## Saída estruturada

Um agente que implementa `HasStructuredOutput` tem seu `schema()` convertido em gramática
pelo Ollama. `enum()` num campo string é o que impede o modelo de inventar um valor — é
assim que a classificação de área garante devolver um dos 24 slugs do catálogo, e não uma
área plausível que não existe.
