<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Reads the facts of a matter and names the area of law they belong to.
 *
 * The 24 areas are handed to the agent rather than fetched by it. A tool call
 * would have matched the shape of the problem, but the schema below is what
 * actually makes the answer trustworthy: `enum` reaches Ollama as a grammar
 * constraint, so an invented area is not something the model is allowed to
 * emit. That guarantee is worth more than the round trip it costs, and it is
 * why the caller resolves the slug against the same collection it passed in.
 *
 * Two things learned from the model itself, both easy to undo by accident:
 *
 * 1. Never send `think: false` to gpt-oss:20b. It answers with empty content.
 *    Left alone, Ollama puts the reasoning in `message.thinking` and the JSON
 *    arrives clean in `message.content`, which is exactly what we want.
 * 2. The justification needs its `description()`. Without one the model
 *    answers with a slug-shaped fragment instead of a sentence.
 *
 * The schema is not repeated in the instructions: the SDK's Ollama gateway
 * already appends it to the system prompt (ComposesSchemaInstructions).
 */
#[Provider('ollama')]
#[Model('gpt-oss:20b')]
#[Timeout(180)]
#[Temperature(0.2)]
final class PracticeAreaClassificationAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  list<array{slug: string, label: string}>  $areas  every area the answer may name
     * @param  string  $knowledge  the markdown guide from app/Rag/knowledge
     */
    public function __construct(
        private readonly array $areas,
        private readonly string $knowledge,
    ) {}

    public function instructions(): string
    {
        return <<<TXT
        Você é um assistente jurídico brasileiro especializado em triagem. Sua única
        tarefa é ler a descrição dos fatos de um caso e dizer a qual área de atuação ele
        pertence, com uma justificativa curta.

        O relato chega em linguagem natural e varia muito: pode ser um parágrafo corrido
        escrito pelo próprio cliente, com erros e sem termos técnicos, ou um resumo já
        redigido por advogado. Classifique os dois com o mesmo critério.

        # Base de conhecimento

        {$this->knowledge}

        # Áreas disponíveis

        Escolha exatamente uma das áreas abaixo e devolva o slug tal como está escrito:

        {$this->areaList()}

        # Regras da resposta

        - `practice_area_slug`: o slug exato de uma das áreas listadas acima.
        - `justification`: duas ou três frases em português do Brasil, citando os fatos
          concretos do relato que sustentam a escolha. Escreva para o advogado que vai
          conferir a classificação: diga o que no relato levou àquela área, e não o que a
          área significa. Se o relato for curto ou ambíguo, diga que a classificação é
          indiciária e o que faltou.
        - Não invente fatos que o relato não traz, não dê conselho jurídico e não sugira
          qual peça ajuizar.
        TXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'practice_area_slug' => $schema->string()
                ->enum(array_column($this->areas, 'slug'))
                ->description('O slug exato de uma das áreas de atuação listadas.')
                ->required(),

            'justification' => $schema->string()
                ->description(
                    'Duas ou três frases em português citando os fatos do relato que '
                    .'sustentam a área escolhida. Frases completas, não palavras soltas.'
                )
                ->required(),
        ];
    }

    private function areaList(): string
    {
        return implode(PHP_EOL, array_map(
            static fn (array $area): string => "- {$area['slug']} — {$area['label']}",
            $this->areas,
        ));
    }
}
