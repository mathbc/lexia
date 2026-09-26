<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Ai\Agents\LegalThemeSelectionAgent;
use App\Domain\LegalCases\Actions\ResearchLegalCaseThemes;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalThemes\Models\LegalTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\AgentPrompt;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The themes RAG end to end, minus the two models: the retrieval hands the
 * candidates to the agent, and the agent's references come back as the ids of
 * rows that exist.
 *
 * Both halves are faked — the embedding with a hand-made vector, the agent with
 * the SDK's fake — so what is pinned is the wiring between them. The agent's
 * judgement is exercised for real in `tests/Agents/LegalThemeSelectionTest`.
 */
final class ResearchLegalCaseThemesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_selected_references_come_back_as_the_themes_ids(): void
    {
        [$account] = $this->accountWithOwner();
        $case = LegalCase::factory()->forAccount($account)->create(['facts' => 'O plano de saúde reajustou a mensalidade aos 60 anos.']);

        $chosen = LegalTheme::factory()->embedded($this->axis(0))->create();
        $shown = LegalTheme::factory()->embedded($this->axis(1))->create();

        Embeddings::fake([[$this->axis(0)]]);
        LegalThemeSelectionAgent::fake([[
            'themes' => [['reference' => $chosen->reference(), 'reason' => 'O reajuste aos 60 anos é a questão do tema.']],
        ]]);

        $research = ResearchLegalCaseThemes::run($case);

        $this->assertSame(2, $research->considered);
        $this->assertCount(1, $research->themes->themes);
        $this->assertSame($chosen->id, $research->themes->themes[0]->legalThemeId);
        $this->assertSame('O reajuste aos 60 anos é a questão do tema.', $research->themes->themes[0]->reason);

        // Os fatos são o prompt; o enquadramento e as candidatas fecham as
        // instruções — e o uuid nunca aparece, só a referência.
        LegalThemeSelectionAgent::assertPrompted(static function (AgentPrompt $prompt) use ($case, $chosen, $shown): bool {
            $instructions = (string) $prompt->agent->instructions();

            return $prompt->prompt === $case->facts
                && str_contains($instructions, "- [{$chosen->reference()}] {$chosen->heading()}")
                && str_contains($instructions, "- [{$shown->reference()}] {$shown->heading()}")
                && str_contains($instructions, "- Área de atuação: {$case->practiceArea->label}")
                && ! str_contains($instructions, $chosen->id);
        });
    }

    /**
     * Zero candidatas seria `enum` vazio — gramática inválida — e, mesmo que
     * não fosse, a resposta "nenhum tema se aplica" seria falsa: os temas nunca
     * foram olhados.
     */
    #[Test]
    public function an_empty_catalogue_throws_without_asking_the_agent(): void
    {
        [$account] = $this->accountWithOwner();
        $case = LegalCase::factory()->forAccount($account)->create(['facts' => 'O plano de saúde reajustou a mensalidade.']);

        Embeddings::fake([[$this->axis(0)]]);
        LegalThemeSelectionAgent::fake([]);

        try {
            ResearchLegalCaseThemes::run($case);
            $this->fail('Um catálogo vazio deveria ter lançado.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('lexia:import-legal-themes', $e->getMessage());
        }

        LegalThemeSelectionAgent::assertNeverPrompted();
    }

    #[Test]
    public function a_pleading_without_facts_is_refused_before_any_retrieval(): void
    {
        [$account] = $this->accountWithOwner();
        $case = LegalCase::factory()->forAccount($account)->create(['facts' => null]);

        Embeddings::fake();
        LegalThemeSelectionAgent::fake([]);

        try {
            ResearchLegalCaseThemes::run($case);
            $this->fail('Uma peça sem fatos deveria ter lançado.');
        } catch (RuntimeException) {
            Embeddings::assertNothingGenerated();
            LegalThemeSelectionAgent::assertNeverPrompted();
        }
    }

    /**
     * @return list<float>
     */
    private function axis(int $index): array
    {
        $vector = array_fill(0, 768, 0.0);
        $vector[$index] = 1.0;

        return $vector;
    }
}
