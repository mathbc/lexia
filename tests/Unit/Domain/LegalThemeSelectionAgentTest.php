<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Ai\Agents\LegalThemeSelectionAgent;
use App\Domain\LegalThemes\Data\LegalCaseThemeListData;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The invariants the themes RAG rests on, asserted where they cost nothing:
 * the schema may only offer the references it was handed, and it may not come
 * back with fewer than the floor — nor ask for more than there are.
 *
 * The retrieval that decides which references those are is pinned in
 * Tests\Feature\LegalThemes\LegalThemeCandidatesQueryTest, and the agent's
 * judgement in tests/Agents/LegalThemeSelectionTest.
 */
final class LegalThemeSelectionAgentTest extends TestCase
{
    #[Test]
    public function the_schema_offers_exactly_the_candidate_references_between_a_floor_and_a_ceiling(): void
    {
        $schema = $this->agent()->schema(new JsonSchemaTypeFactory)['themes']->toArray();

        $this->assertSame(LegalCaseThemeListData::MAX_THEMES, $schema['maxItems']);
        $this->assertSame(['theme-952', 'theme-1474'], $schema['items']['properties']['reference']['enum']);

        // Duas candidatas e um piso de três: um `minItems` acima do `enum`
        // seria um schema que nenhuma resposta satisfaz.
        $this->assertSame(2, $schema['minItems']);
        $this->assertStringContainsString('de 2 a '.LegalCaseThemeListData::MAX_THEMES.' temas', $this->agent()->instructions());
    }

    /**
     * O que varia vai no fim, pelo cache de prefixo — e o tema sem tese diz
     * que não tem, em vez de deixar o modelo supor que tem.
     */
    #[Test]
    public function the_candidates_close_the_instructions_and_say_what_they_lack(): void
    {
        $instructions = $this->agent()->instructions();

        $candidates = (int) strrpos($instructions, '# Os temas candidatos');
        $questions = (int) strrpos($instructions, '# As questões de direito do caso');
        $framing = (int) strrpos($instructions, '# O enquadramento da peça');

        $this->assertGreaterThan($framing, $questions);
        $this->assertGreaterThan($questions, $candidates);
        $this->assertStringContainsString('2. Definir se cabe restituição em dobro.', $instructions);
        $this->assertGreaterThan(
            $candidates,
            (int) strpos($instructions, '- [theme-952] Tema Repetitivo 952 — Segunda Seção, situação: Trânsito em Julgado'),
        );
        $this->assertStringContainsString('  Tese firmada: ainda não firmada.', $instructions);
        $this->assertStringContainsString('  Repercussão geral no STF: Tema 69 — Inclusão do ICMS.', $instructions);
        $this->assertStringContainsString('- Área de atuação: Direito do Consumidor', $instructions);
    }

    private function agent(): LegalThemeSelectionAgent
    {
        return new LegalThemeSelectionAgent(
            framing: "## O enquadramento\n\n- Área de atuação: Direito do Consumidor",
            questions: ['Definir se o reajuste por faixa etária é válido.', 'Definir se cabe restituição em dobro.'],
            candidates: [
                [
                    'reference' => 'theme-952',
                    'heading' => 'Tema Repetitivo 952',
                    'status' => 'Trânsito em Julgado',
                    'judging_body' => 'Segunda Seção',
                    'question' => 'Validade do reajuste por faixa etária em plano de saúde.',
                    'settled_thesis' => 'O reajuste é válido desde que haja previsão contratual.',
                    'judgment_scope' => null,
                    'repercussions' => ['Tema 69 — Inclusão do ICMS.'],
                ],
                [
                    'reference' => 'theme-1474',
                    'heading' => 'Tema Repetitivo 1474',
                    'status' => 'Afetado',
                    'judging_body' => null,
                    'question' => 'Capitalização diária de juros em contrato bancário.',
                    'settled_thesis' => null,
                    'judgment_scope' => null,
                    'repercussions' => [],
                ],
            ],
        );
    }
}
