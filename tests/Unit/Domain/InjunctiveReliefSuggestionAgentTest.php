<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Ai\Agents\InjunctiveReliefSuggestionAgent;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shape of the urgent-relief answer and the order of its prompt, asserted
 * where it costs nothing. Whether the agent weighs urgency well is the business
 * of tests/Agents/InjunctiveReliefSuggestionTest, against the real model.
 */
final class InjunctiveReliefSuggestionAgentTest extends TestCase
{
    /**
     * A espécie é escolha, e o nulo é a resposta de quem não recomenda: sem ele
     * no `enum`, a gramática obrigaria a recusa a inventar uma espécie.
     */
    #[Test]
    public function the_kind_is_one_of_the_two_species_or_null(): void
    {
        $kind = $this->schema()['kind']->toArray();

        $this->assertSame(['anticipatory', 'precautionary', null], $kind['enum']);
    }

    /**
     * Um teto só, num nível só: tetos empilhados em listas aninhadas são 400 no
     * Gemini.
     */
    #[Test]
    public function the_evidence_is_a_flat_list_with_one_ceiling(): void
    {
        $evidence = $this->schema()['evidence']->toArray();

        $this->assertSame('array', $evidence['type']);
        $this->assertSame('string', $evidence['items']['type']);
        $this->assertSame(5, $evidence['maxItems']);
    }

    /**
     * Toda parte da medida existe mesmo quando é nula: a recusa responde com
     * as chaves vazias, e não com as chaves ausentes.
     */
    #[Test]
    public function every_part_of_the_measure_is_required_and_nullable(): void
    {
        $schema = $this->schema();

        foreach (['measure', 'legal_basis', 'probability', 'danger', 'reversibility'] as $part) {
            $this->assertContains('null', (array) $schema[$part]->toArray()['type'], "{$part} não aceita nulo.");
        }
    }

    /**
     * O que varia vai no fim, pelo cache de prefixo — a área e a classe com a
     * descrição dela, que é o que diz se o rito tem liminar própria.
     */
    #[Test]
    public function the_area_and_the_class_close_the_instructions(): void
    {
        $instructions = $this->agent()->instructions();

        $this->assertStringEndsWith(
            '[1118] Ação de Despejo — Liminar para desocupação em quinze dias.',
            $instructions,
        );
        $this->assertStringContainsString('A área de atuação deste caso é **Direito Imobiliário**.', $instructions);
        $this->assertStringContainsString('# Base de conhecimento'.PHP_EOL.PHP_EOL.'O guia.', $instructions);
    }

    /**
     * Sem classe decidida o agente ainda responde, e é dito que o fundamento é
     * o geral.
     */
    #[Test]
    public function without_a_class_the_agent_is_told_to_answer_on_the_general_rule(): void
    {
        $instructions = (new InjunctiveReliefSuggestionAgent('Direito Civil', null, 'O guia.'))->instructions();

        $this->assertStringEndsWith('com o fundamento geral do art. 300 do CPC.', $instructions);
    }

    /**
     * @return array<string, Type>
     */
    private function schema(): array
    {
        return $this->agent()->schema(new JsonSchemaTypeFactory);
    }

    private function agent(): InjunctiveReliefSuggestionAgent
    {
        return new InjunctiveReliefSuggestionAgent(
            areaLabel: 'Direito Imobiliário',
            classLine: '[1118] Ação de Despejo — Liminar para desocupação em quinze dias.',
            knowledge: 'O guia.',
        );
    }
}
