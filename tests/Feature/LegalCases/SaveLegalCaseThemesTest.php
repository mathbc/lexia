<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Domain\LegalCases\Actions\SaveLegalCaseThemes;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalThemes\Data\LegalCaseThemeData;
use App\Domain\LegalThemes\Data\LegalCaseThemeListData;
use App\Domain\LegalThemes\Enums\LegalThemeType;
use App\Domain\LegalThemes\Models\LegalTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The N-N between a pleading and the STJ themes it leans on: written by
 * `sync()`, read back by the fifth step's second tab.
 *
 * No route points at the Action, so it is called directly; the route that
 * carries its payload — the "Concluir" — is pinned in FinalizeLegalCaseTest.
 */
final class SaveLegalCaseThemesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_links_the_themes_with_the_account_and_the_reason(): void
    {
        [$account] = $this->accountWithOwner();
        $case = LegalCase::factory()->forAccount($account)->create(['current_step' => LegalCaseStep::Documents]);
        $theme = LegalTheme::factory()->create();

        SaveLegalCaseThemes::run($case, $this->list([$theme->id => 'Aplica-se.']));

        $linked = $case->themes()->sole();

        $this->assertSame($theme->id, $linked->id);
        $this->assertSame('Aplica-se.', $linked->pivot->reason);
        $this->assertSame($account->id, $linked->pivot->account_id);
        $this->assertNotEmpty($linked->pivot->id);

        // As duas abas são uma etapa só: vincular um tema levanta a mesma marca
        // d'água que gravar as teses.
        $this->assertSame(LegalCaseStep::Review, $case->refresh()->current_step);
    }

    /**
     * O `sync()` é o diff: o que ficou atualiza a razão, o que saiu é
     * desvinculado, e o tema continua no catálogo.
     */
    #[Test]
    public function saving_again_updates_what_stayed_and_unlinks_what_left(): void
    {
        [$account] = $this->accountWithOwner();
        $case = LegalCase::factory()->forAccount($account)->create(['current_step' => LegalCaseStep::Review]);
        [$kept, $left] = LegalTheme::factory()->count(2)->create();

        SaveLegalCaseThemes::run($case, $this->list([$kept->id => 'Primeira razão.', $left->id => 'Sai.']));
        $link = $case->themes()->whereKey($kept->id)->sole()->pivot->id;

        SaveLegalCaseThemes::run($case, $this->list([$kept->id => 'Segunda razão.']));

        $linked = $case->themes()->sole();

        $this->assertSame($kept->id, $linked->id);
        $this->assertSame('Segunda razão.', $linked->pivot->reason);
        $this->assertSame($link, $linked->pivot->id);
        $this->assertDatabaseMissing('legal_case_themes', ['legal_theme_id' => $left->id]);
        $this->assertDatabaseHas('legal_themes', ['id' => $left->id]);
    }

    #[Test]
    public function an_empty_list_unlinks_every_theme(): void
    {
        [$account] = $this->accountWithOwner();
        $case = LegalCase::factory()->forAccount($account)->create(['current_step' => LegalCaseStep::Review]);
        $theme = LegalTheme::factory()->create();

        SaveLegalCaseThemes::run($case, $this->list([$theme->id => null]));
        SaveLegalCaseThemes::run($case, new LegalCaseThemeListData([]));

        $this->assertSame(0, $case->themes()->count());
    }

    /**
     * Reabrir a peça devolve a aba como ela ficou: na ordem do ranking, o id do
     * tema — é o que o "Concluir" posta de volta —, os rótulos já em
     * português, as repercussões embaixo, e o vetor, nunca.
     */
    #[Test]
    public function the_linked_themes_hydrate_the_step_when_the_pleading_is_reopened(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $case = LegalCase::factory()->forAccount($account)->create([
            'current_step' => LegalCaseStep::Review,
            'theme_findings' => ['considered' => 12, 'questions' => ['Definir se o reajuste é válido.'], 'researched_at' => '2026-09-26T10:00:00-03:00'],
        ]);

        $controversy = LegalTheme::factory()->embedded(array_fill(0, 768, 0.1))->create([
            'type' => LegalThemeType::Controversy,
            'number' => 7,
        ]);
        $theme = LegalTheme::factory()->create(['number' => 952, 'settled_thesis' => 'O reajuste é válido.']);
        $theme->generalRepercussions()->create(['number' => 69, 'description' => 'Inclusão do ICMS na base de cálculo do PIS e da COFINS.']);

        SaveLegalCaseThemes::run($case, $this->list([
            $controversy->id => 'Controvérsia vizinha.',
            $theme->id => 'O relato discute o reajuste.',
        ]));

        $this->actingAs($owner)
            ->get(route('legal-cases.edit', $case))
            ->assertInertia(fn ($page) => $page
                ->component('legal-cases/form')
                // A Controvérsia antes do Tema Repetitivo: a ordem é a do
                // ranking, e não a do tipo.
                ->where('legalCase.themes.0.id', $controversy->id)
                ->where('legalCase.themes.0.heading', 'Controvérsia 7')
                ->where('legalCase.themes.1.id', $theme->id)
                ->where('legalCase.themes.1.heading', 'Tema Repetitivo 952')
                ->where('legalCase.themes.1.judging_body', $theme->judging_body?->label())
                ->where('legalCase.themes.1.settled_thesis', 'O reajuste é válido.')
                ->where('legalCase.themes.1.reason', 'O relato discute o reajuste.')
                ->where('legalCase.themes.1.general_repercussions.0.number', 69)
                ->missing('legalCase.themes.1.embedding')
                ->where('legalCase.theme_research.considered', 12)
                ->where('legalCase.theme_research.questions', ['Definir se o reajuste é válido.']));
    }

    /**
     * O "Concluir" posta os mantidos na ordem da tela, e a gravação seguinte
     * reescreve a posição dos vínculos que ficaram — o ranking sobrevive à
     * curadoria sem que nenhum vínculo seja recriado.
     */
    #[Test]
    public function a_second_save_rewrites_the_rank_of_the_links_that_stayed(): void
    {
        [$account] = $this->accountWithOwner();
        $case = LegalCase::factory()->forAccount($account)->create(['current_step' => LegalCaseStep::Review]);

        [$first, $second, $third] = LegalTheme::factory()->count(3)->create();

        SaveLegalCaseThemes::run($case, $this->list([$first->id => null, $second->id => null, $third->id => null]));
        SaveLegalCaseThemes::run($case, $this->list([$third->id => null, $first->id => null]));

        $this->assertSame([$third->id, $first->id], $case->themes()->pluck('legal_themes.id')->all());
        $this->assertSame([0, 1], $case->themes()->get()->map(static fn (LegalTheme $theme): mixed => $theme->getRelationValue('pivot')?->position)->all());
    }

    #[Test]
    public function a_pleading_never_selected_hydrates_a_null_marker(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $case = LegalCase::factory()->forAccount($account)->create(['current_step' => LegalCaseStep::Review]);

        $this->actingAs($owner)
            ->get(route('legal-cases.edit', $case))
            ->assertInertia(fn ($page) => $page
                ->where('legalCase.themes', [])
                ->where('legalCase.theme_research', null));
    }

    /**
     * @param  array<string, string|null>  $themes  theme id => reason
     */
    private function list(array $themes): LegalCaseThemeListData
    {
        return new LegalCaseThemeListData(array_map(
            static fn (string $id, ?string $reason): LegalCaseThemeData => new LegalCaseThemeData($id, $reason),
            array_keys($themes),
            array_values($themes),
        ));
    }
}
