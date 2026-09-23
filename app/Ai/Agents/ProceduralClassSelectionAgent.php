<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Concerns\ConfiguresOllamaRuntime;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Picks the CNJ procedural class a matter should be filed under.
 *
 * The second half of the classification, and it cannot be folded into the
 * first: the candidates only exist once the area is known, and the candidates
 * are what the `enum` is built from. Constraining the answer is worth an extra
 * round trip — the same trade PracticeAreaClassificationAgent documents.
 *
 * The answer is the CNJ `code`, an integer, and not the slug: procedural class
 * slugs are *not* unique (559 distinct across 615 rows — `acao-rescisoria`
 * alone repeats three times), while `code` is unique, is the number that
 * travels in DataJud and PJe, and is what a lawyer recognises. The uuid never
 * enters the prompt: it is generated when the catalogue migration loads and
 * differs between databases.
 *
 * Two renderings in the candidate list, and what decides between them is
 * relevance, not scope. The caller ranks the candidates against the facts with
 * the pgvector embeddings and describes only the closest ones — description,
 * typical matters and the statutes a pleading under that class cites. The rest
 * arrive as a name and a code: still answerable, because every candidate is in
 * the `enum`, just not worth the window. The civil trunk is described once,
 * with its tie-breakers, in `app/Rag/knowledge/procedural-classes.md`.
 *
 * That budget is what pays for the enriched catalogue. The descriptions now
 * carry the deadline, the trigger and the practical instruments — "citação
 * para pagar em três dias", "30 dias contados da garantia do juízo" — which is
 * what lets a lay narrative reach the right class, and spelling all 45 out on
 * the worst area would overrun the context window beside the knowledge guide.
 *
 * ## Why the area label is at the bottom of the prompt
 *
 * Ollama caches the KV of a prompt prefix and reuses it while the prefix is
 * byte-identical. Measured on this project's own knowledge guide: a constant
 * prefix prefills in 0.07s against 3.12s cold, a 44x difference. A single
 * variable token near the top voids all of it — three calls differing only in
 * an interpolated area name cost 3.11s, 3.10s, 3.11s, every one of them full
 * price.
 *
 * This prompt used to open with the area, which put a variable at line two and
 * meant the 13 KB knowledge guide below it was re-prefilled on every single
 * classification. Everything constant now comes first and the two variable
 * blocks — the area and the ranked candidates — come last, together, where they
 * cost only themselves. Moving either one back up is a silent performance
 * regression, which is the only kind this file can have. A medida é do daemon,
 * e o desenho não custa nada no Gemini: o cache implícito da nuvem premia o
 * mesmo prefixo constante, e a volta para a máquina reencontra o arranjo certo.
 *
 * The traps the sibling agent documents apply here unchanged: never send
 * `think: false` to an Ollama that reasons — dormant while the attribute below
 * points at Gemini, live again on the swap back — never drop the
 * `description()` on the justification, and leave the model to `config/ai.php`
 * rather than naming one in a `#[Model]`. So does the note on where the `enum`
 * guarantee comes from: a cloud `response_json_schema` today, Ollama's grammar
 * under the commented provider, which matters here because this agent's `enum`
 * is a list of integers.
 */
// Trocar as duas linhas de lugar traz a inferência de volta para a máquina — a
// troca mais um `config:clear` bastam, com o `gpt-oss:20b` baixado no daemon.
#[Provider('gemini')]
// #[Provider('ollama')]
#[Timeout(360)]
#[Temperature(0.2)]
final class ProceduralClassSelectionAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use ConfiguresOllamaRuntime;
    use Promptable;

    /**
     * @param  string  $areaLabel  the area already decided, in the lawyer's words
     * @param  list<array{code: int, name: string, scope: string, description: string|null, subjects: list<string>, legal_bases: list<string>}>  $candidates
     * @param  string  $knowledge  the markdown guide from app/Rag/knowledge
     */
    public function __construct(
        private readonly string $areaLabel,
        private readonly array $candidates,
        private readonly string $knowledge,
    ) {}

    public function instructions(): string
    {
        return <<<TXT
        Você é um assistente jurídico brasileiro especializado em triagem. A área de
        atuação do caso já foi decidida por outro agente e vem declarada ao fim destas
        instruções, junto das classes processuais candidatas. Sua única tarefa é ler a
        descrição dos fatos e escolher, entre aquelas classes, a classe sob a qual a
        peça deve ser autuada, com uma justificativa curta.

        O relato chega em linguagem natural e varia muito: pode ser um parágrafo corrido
        escrito pelo próprio cliente, com erros e sem termos técnicos, ou um resumo já
        redigido por advogado. Trate os dois com o mesmo critério.

        A área sai dos fatos; a classe sai do **pedido** — do que se quer do juiz.

        # Base de conhecimento

        {$this->knowledge}

        # Regras da resposta

        - `procedural_class_code`: o código CNJ exato de uma das classes listadas ao
          fim destas instruções.
        - `justification`: duas ou três frases em português do Brasil, citando os fatos
          concretos do relato e o pedido que deles decorre. Escreva para o advogado que
          vai conferir a escolha: diga o que no relato leva àquela classe, e não o que a
          classe significa. Se o relato for curto ou ambíguo, diga que a escolha é
          indiciária e o que faltou.
        - Não invente fatos que o relato não traz e não dê conselho jurídico.

        # A área decidida e as classes disponíveis

        A área de atuação deste caso é **{$this->areaLabel}**, e as classes abaixo são
        as que lhe são vinculadas.

        Escolha exatamente uma delas e devolva o código entre colchetes, apenas o
        número. A lista vem ordenada da mais próxima do relato para a mais distante,
        mas a ordem é só uma sugestão de leitura: a classe certa pode estar em qualquer
        posição. As que chegam só com o nome são igualmente escolhíveis — estão sem
        descrição por economia de espaço, e as do tronco cível estão descritas na base
        de conhecimento acima.

        {$this->candidateList()}
        TXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'procedural_class_code' => $schema->integer()
                ->enum(array_column($this->candidates, 'code'))
                ->description('O código CNJ exato de uma das classes processuais listadas.')
                ->required(),

            'justification' => $schema->string()
                ->description(
                    'Duas ou três frases em português citando os fatos do relato e o '
                    .'pedido que sustentam a classe escolhida. Frases completas, não '
                    .'palavras soltas.'
                )
                ->required(),
        ];
    }

    private function candidateList(): string
    {
        return implode(PHP_EOL, array_map(
            fn (array $candidate): string => $this->line($candidate),
            $this->candidates,
        ));
    }

    /**
     * @param  array{code: int, name: string, scope: string, description: string|null, subjects: list<string>, legal_bases: list<string>}  $candidate
     */
    private function line(array $candidate): string
    {
        $line = "- [{$candidate['code']}] {$candidate['name']}";

        if ($candidate['description'] === null) {
            return $line;
        }

        $line .= " — {$candidate['description']}";

        if ($candidate['subjects'] !== []) {
            $line .= ' Matérias típicas: '.implode(', ', $candidate['subjects']).'.';
        }

        // A fundamentação entra porque desempata: duas classes podem descrever
        // situações parecidas e se separarem pelo dispositivo que invocam — os
        // 30 dias do art. 16 da LEF contra os 15 do art. 915 do CPC.
        return $candidate['legal_bases'] === []
            ? $line
            : $line.' Base legal: '.implode('; ', $candidate['legal_bases']).'.';
    }
}
