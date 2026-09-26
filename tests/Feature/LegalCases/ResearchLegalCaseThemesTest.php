<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Ai\Agents\LegalQuestionFormulationAgent;
use App\Ai\Agents\LegalThemeSelectionAgent;
use App\Domain\LegalCases\Actions\ResearchLegalCaseThemes;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalThemes\Data\LegalCaseThemeData;
use App\Domain\LegalThemes\Models\LegalTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The themes RAG end to end, minus the models: the formulation's questions are
 * what the retrieval searches with, the retrieval hands the candidates to the
 * selection, and the selection's references come back as the ids of rows that
 * exist.
 *
 * Every model is faked — the embedding with hand-made vectors, the two agents
 * with the SDK's fake — so what is pinned is the wiring between them. Their
 * judgement is exercised for real in `tests/Agents/LegalThemeSelectionTest`.
 */
final class ResearchLegalCaseThemesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_questions_search_the_catalogue_and_the_ranking_comes_back_as_the_themes_ids(): void
    {
        [$account] = $this->accountWithOwner();
        $case = LegalCase::factory()->forAccount($account)->create(['facts' => 'O plano de saúde reajustou a mensalidade aos 60 anos.']);

        $second = LegalTheme::factory()->embedded($this->axis(0))->create();
        $first = LegalTheme::factory()->embedded($this->axis(1))->create();
        $untouched = LegalTheme::factory()->embedded($this->axis(2))->create();

        $questions = ['Definir se é válido o reajuste por faixa etária.', 'Definir se cabe restituição em dobro.'];

        LegalQuestionFormulationAgent::fake([['questions' => [...$questions, '  ', $questions[0]]]]);
        Embeddings::fake([[$this->axis(0), $this->axis(1)]]);
        LegalThemeSelectionAgent::fake([[
            'themes' => [
                ['reference' => $first->reference(), 'reason' => 'A restituição em dobro é a questão do tema.'],
                ['reference' => $second->reference(), 'reason' => 'O reajuste aos 60 anos é a questão do tema.'],
                ['reference' => $untouched->reference(), 'reason' => 'Relação indireta.'],
            ],
        ]]);

        $research = ResearchLegalCaseThemes::run($case);

        // Em branco e repetida caem antes da busca, e é com o que sobra que o
        // catálogo foi consultado e que o marcador conta.
        $this->assertSame($questions, $research->questions);
        $this->assertSame(3, $research->considered);
        $this->assertSame([$first->id, $second->id, $untouched->id], array_map(
            static fn (LegalCaseThemeData $theme): string => $theme->legalThemeId,
            $research->themes->themes,
        ));
        $this->assertSame('A restituição em dobro é a questão do tema.', $research->themes->themes[0]->reason);

        Embeddings::assertGenerated(static fn (EmbeddingsPrompt $prompt): bool => $prompt->inputs === [
            'search_query: '.$questions[0],
            'search_query: '.$questions[1],
        ]);

        // Os fatos são o prompt dos dois agentes; o enquadramento fecha as
        // instruções do primeiro, e o segundo recebe também as questões e as
        // candidatas — pela referência, nunca pelo uuid.
        LegalQuestionFormulationAgent::assertPrompted(static fn (AgentPrompt $prompt): bool => $prompt->prompt === $case->facts
            && str_contains((string) $prompt->agent->instructions(), "- Área de atuação: {$case->practiceArea->label}"));

        LegalThemeSelectionAgent::assertPrompted(static function (AgentPrompt $prompt) use ($case, $first, $second, $questions): bool {
            $instructions = (string) $prompt->agent->instructions();

            return $prompt->prompt === $case->facts
                && str_contains($instructions, "1. {$questions[0]}")
                && str_contains($instructions, "- [{$first->reference()}] {$first->heading()}")
                && str_contains($instructions, "- [{$second->reference()}] {$second->heading()}")
                && str_contains($instructions, "- Área de atuação: {$case->practiceArea->label}")
                && ! str_contains($instructions, $first->id);
        });
    }

    /**
     * Sem questão não há consulta, e cair de volta no relato cru seria
     * reinstalar a busca que devolvia zero temas. A metade falha.
     */
    #[Test]
    public function no_question_fails_the_half_before_any_retrieval(): void
    {
        [$account] = $this->accountWithOwner();
        $case = LegalCase::factory()->forAccount($account)->create(['facts' => 'O plano de saúde reajustou a mensalidade.']);

        LegalTheme::factory()->embedded($this->axis(0))->create();

        LegalQuestionFormulationAgent::fake([['questions' => ['   ']]]);
        Embeddings::fake();
        LegalThemeSelectionAgent::fake([]);

        try {
            ResearchLegalCaseThemes::run($case);
            $this->fail('Sem questão formulada a metade deveria ter falhado.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('questão de direito', $e->getMessage());
        }

        Embeddings::assertNothingGenerated();
        LegalThemeSelectionAgent::assertNeverPrompted();
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

        LegalQuestionFormulationAgent::fake([['questions' => ['Definir se é válido o reajuste.']]]);
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

        LegalQuestionFormulationAgent::fake([]);
        Embeddings::fake();
        LegalThemeSelectionAgent::fake([]);

        try {
            ResearchLegalCaseThemes::run($case);
            $this->fail('Uma peça sem fatos deveria ter lançado.');
        } catch (RuntimeException) {
            LegalQuestionFormulationAgent::assertNeverPrompted();
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
