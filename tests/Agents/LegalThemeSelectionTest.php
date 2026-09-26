<?php

declare(strict_types=1);

namespace Tests\Agents;

use App\Domain\LegalCases\Actions\ResearchLegalCaseThemes;
use App\Domain\LegalCases\Data\LegalThemeResearchData;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalThemes\Actions\EmbedLegalThemes;
use App\Domain\LegalThemes\Actions\ImportLegalThemes;
use App\Domain\LegalThemes\Data\LegalCaseThemeData;
use App\Domain\LegalThemes\Data\LegalCaseThemeListData;
use App\Domain\LegalThemes\Models\LegalTheme;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\LegalThemes\ImportLegalThemesTest;
use Tests\TestCase;

/**
 * The themes RAG against the real models, no fakes: nomic on the daemon for
 * both sides of the vector, and the selection agent on its provider.
 *
 * The catalogue is the nine-row fixture the import tests use, embedded for real
 * in `setUp()` — a few seconds on Ollama. That is small enough that the
 * retrieval hands the agent every theme, which is the point: what this file
 * measures is the reading, not the ranking. The ranking is pinned
 * deterministically in `LegalThemeCandidatesQueryTest`.
 *
 * The pleading is built in memory with its two relations set by hand, as in the
 * other agent tests. RefreshDatabase is for the catalogue alone.
 *
 * ## What is pinned, and what deliberately is not
 *
 * Held firmly: the contract — every selected id is a theme of the catalogue,
 * the ceiling holds, every reason is written. Held loosely, with a message: the
 * one judgement a smoke test can make without pinning a legal opinion — a
 * narrative that *is* the question of Tema 952 selects it, and one about a
 * collapsed wall selects nothing about health plans.
 *
 * ```bash
 * composer test:agents
 * ```
 */
#[Group('agents')]
final class LegalThemeSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ImportLegalThemes::run(ImportLegalThemesTest::FIXTURE);
        EmbedLegalThemes::run(LegalTheme::all());
    }

    #[Test]
    public function a_narrative_that_is_the_question_of_a_theme_selects_it(): void
    {
        $facts = <<<'TXT'
        Tenho um plano de saúde individual há quinze anos. No mês em que completei
        59 anos a operadora aumentou a minha mensalidade em 89%, alegando mudança de
        faixa etária prevista no contrato. Não me apresentaram nenhum cálculo que
        justificasse o percentual, e com esse valor não consigo mais pagar. Quero
        revisar o reajuste e receber de volta o que paguei a mais.
        TXT;

        $research = ResearchLegalCaseThemes::run($this->pleading($facts));

        $this->show($facts, $research);
        $this->assertContractHolds($research);

        $this->assertContains(
            $this->theme(952)->id,
            $this->ids($research),
            'O relato é a questão do Tema 952 e o agente não o selecionou.',
        );
    }

    #[Test]
    public function a_narrative_that_touches_no_theme_selects_nothing_about_health_plans(): void
    {
        $facts = <<<'TXT'
        O meu vizinho fez uma obra no terreno dele e o muro que divide as duas casas
        desabou sobre o meu quintal, destruindo a horta e a casinha do cachorro. Ele
        se recusa a pagar pela reconstrução e diz que o muro já estava velho.
        TXT;

        $research = ResearchLegalCaseThemes::run($this->pleading($facts));

        $this->show($facts, $research);
        $this->assertContractHolds($research);

        $this->assertNotContains(
            $this->theme(952)->id,
            $this->ids($research),
            'O agente selecionou um tema de plano de saúde para um muro desabado.',
        );
    }

    private function assertContractHolds(LegalThemeResearchData $research): void
    {
        $this->assertSame(LegalTheme::query()->count(), $research->considered);
        $this->assertLessThanOrEqual(LegalCaseThemeListData::MAX_THEMES, count($research->themes->themes));

        foreach ($research->themes->themes as $theme) {
            $this->assertTrue(LegalTheme::query()->whereKey($theme->legalThemeId)->exists());
            $this->assertNotNull($theme->reason, 'Um tema selecionado veio sem razão.');
        }
    }

    /**
     * @return list<string>
     */
    private function ids(LegalThemeResearchData $research): array
    {
        return array_map(static fn (LegalCaseThemeData $theme): string => $theme->legalThemeId, $research->themes->themes);
    }

    private function theme(int $number): LegalTheme
    {
        return LegalTheme::query()->where('type', 'theme')->where('number', $number)->sole();
    }

    private function pleading(string $facts): LegalCase
    {
        $legalCase = new LegalCase(['facts' => $facts, 'injunctive_relief' => false]);

        $legalCase->setRelation('practiceArea', new PracticeArea(['slug' => 'consumidor', 'label' => 'Direito do Consumidor']));
        $legalCase->setRelation('proceduralClass', new ProceduralClass(['code' => 7, 'name' => 'Procedimento Comum Cível']));

        return $legalCase;
    }

    private function show(string $facts, LegalThemeResearchData $research): void
    {
        fwrite(STDERR, PHP_EOL.'— Relato —'.PHP_EOL.trim($facts).PHP_EOL.'— Temas —'.PHP_EOL);

        foreach ($research->themes->themes as $theme) {
            $heading = LegalTheme::query()->findOrFail($theme->legalThemeId)->heading();

            fwrite(STDERR, "{$heading}: {$theme->reason}".PHP_EOL);
        }

        fwrite(STDERR, "({$research->considered} considerados)".PHP_EOL);
    }
}
