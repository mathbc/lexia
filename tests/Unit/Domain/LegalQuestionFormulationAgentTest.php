<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Ai\Agents\LegalQuestionFormulationAgent;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\HasTools;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shape of the questions the themes retrieval searches with, asserted where
 * it costs nothing. Whether the questions are any good is the business of
 * tests/Agents/LegalThemeSelectionTest, against the real models.
 */
final class LegalQuestionFormulationAgentTest extends TestCase
{
    /**
     * A aba de temas lê só o banco: o catálogo importado do STJ e os vetores
     * dele. Sem ferramenta, o agente não tem como abrir página nenhuma — e um
     * portal fora do ar não tem como derrubar esta aba junto com a de teses.
     */
    #[Test]
    public function it_has_no_tools_and_never_reaches_the_web(): void
    {
        $this->assertNotContains(HasTools::class, class_implements(LegalQuestionFormulationAgent::class));
    }

    #[Test]
    public function the_schema_bounds_the_questions_on_one_array(): void
    {
        $schema = $this->agent()->schema(new JsonSchemaTypeFactory)['questions']->toArray();

        $this->assertSame('array', $schema['type']);
        $this->assertSame('string', $schema['items']['type']);
        $this->assertSame(LegalQuestionFormulationAgent::MIN_QUESTIONS, $schema['minItems']);
        $this->assertSame(LegalQuestionFormulationAgent::MAX_QUESTIONS, $schema['maxItems']);
    }

    /**
     * O que varia vai no fim, pelo cache de prefixo; e o agente é proibido de
     * nomear tema, porque ele não vê o catálogo.
     */
    #[Test]
    public function the_framing_closes_the_instructions(): void
    {
        $instructions = $this->agent()->instructions();

        $this->assertStringEndsWith('- Área de atuação: Direito Penal e Processo Penal', $instructions);
        $this->assertStringContainsString('Não cite número de tema, súmula, recurso ou processo', $instructions);
    }

    private function agent(): LegalQuestionFormulationAgent
    {
        return new LegalQuestionFormulationAgent("## O enquadramento\n\n- Área de atuação: Direito Penal e Processo Penal");
    }
}
