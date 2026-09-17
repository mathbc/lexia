<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Concerns\UsesConfiguredContextWindow;
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
 * Two renderings in the candidate list, for one reason. A class the area owns
 * arrives whole, description and matters included. A class of the civil trunk —
 * the 24 generic ones that show up in nearly every civil area — arrives as a
 * name only, because the trunk is described once, with its tie-breakers, in
 * `app/Rag/knowledge/procedural-classes.md`. Spelling all of them out in every
 * prompt costs ~7 KB on the worst area and says less than the guide does.
 *
 * The traps the sibling agent documents apply here unchanged: never send
 * `think: false`, never drop the `description()` on the justification, and
 * leave the model to `config/ai.php` rather than naming one in a `#[Model]`.
 */
#[Provider('ollama')]
#[Timeout(180)]
#[Temperature(0.2)]
final class ProceduralClassSelectionAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;
    use UsesConfiguredContextWindow;

    /**
     * @param  string  $areaLabel  the area already decided, in the lawyer's words
     * @param  list<array{code: int, name: string, scope: string, description: string|null, subjects: list<string>}>  $candidates
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
        atuação do caso já foi decidida: **{$this->areaLabel}**. Sua única tarefa agora é
        ler a descrição dos fatos e escolher, entre as classes processuais listadas
        abaixo, aquela sob a qual a peça deve ser autuada, com uma justificativa curta.

        O relato chega em linguagem natural e varia muito: pode ser um parágrafo corrido
        escrito pelo próprio cliente, com erros e sem termos técnicos, ou um resumo já
        redigido por advogado. Trate os dois com o mesmo critério.

        A área sai dos fatos; a classe sai do **pedido** — do que se quer do juiz.

        # Base de conhecimento

        {$this->knowledge}

        # Classes disponíveis

        Escolha exatamente uma das classes abaixo e devolva o código entre colchetes,
        apenas o número. As classes sem descrição são as do tronco cível, descritas na
        base de conhecimento acima.

        {$this->candidateList()}

        # Regras da resposta

        - `procedural_class_code`: o código CNJ exato de uma das classes listadas acima.
        - `justification`: duas ou três frases em português do Brasil, citando os fatos
          concretos do relato e o pedido que deles decorre. Escreva para o advogado que
          vai conferir a escolha: diga o que no relato leva àquela classe, e não o que a
          classe significa. Se o relato for curto ou ambíguo, diga que a escolha é
          indiciária e o que faltou.
        - Não invente fatos que o relato não traz e não dê conselho jurídico.
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
     * @param  array{code: int, name: string, scope: string, description: string|null, subjects: list<string>}  $candidate
     */
    private function line(array $candidate): string
    {
        $line = "- [{$candidate['code']}] {$candidate['name']}";

        if ($candidate['scope'] !== 'specific') {
            return $line;
        }

        if ($candidate['description'] !== null) {
            $line .= " — {$candidate['description']}";
        }

        return $candidate['subjects'] === []
            ? $line
            : $line.' Matérias típicas: '.implode(', ', $candidate['subjects']).'.';
    }
}
