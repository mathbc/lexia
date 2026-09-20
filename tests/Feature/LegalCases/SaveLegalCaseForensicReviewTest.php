<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Domain\Accounts\Models\Account;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalPrecedents\Models\LegalPrecedent;
use App\Domain\LegalTheses\Enums\LegalThesisType;
use App\Domain\LegalTheses\Models\LegalThesis;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The sixth step: what the pleading argues, and what sustains it.
 *
 * Two lists written whole and reconciled as a diff, like the requirements step —
 * and one thing that step does not have: a precedent quotes the id of the thesis
 * it grounds, and that quote may name a thesis created in the same submission.
 * Most of what is pinned down here is that the quote is treated as a hint and
 * resolved through the Action's map, never followed into the database.
 */
final class SaveLegalCaseForensicReviewTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_writes_both_lists_and_links_them(): void
    {
        [, $owner, $case] = $this->pleading();

        $this->save($owner, $case, [
            ['id' => self::UUID_A, 'name' => 'Do Cabimento da Exceção de Pré-Executividade sem Garantia do Juízo', 'type' => 'preliminary', 'description' => 'Matéria de ordem pública que independe de dilação probatória.', 'impact' => 'Garante o conhecimento imediato da defesa.', 'legal_bases' => [
                ['type' => 'sumula', 'reference' => 'Súmula 393 do STJ', 'source' => 'STJ'],
                ['type' => 'statute', 'reference' => 'Lei nº 6.830/80', 'source' => null],
            ]],
        ], [
            ['id' => null, 'legal_thesis_id' => self::UUID_A, 'name' => 'STJ — Súmula nº 393/STJ', 'type' => 'sumula', 'description' => 'A exceção de pré-executividade é admissível na execução fiscal.', 'citation' => 'BRASIL. Superior Tribunal de Justiça. Súmula nº 393.', 'grounding' => 'Fundamenta o cabimento sem garantia do juízo.', 'adherence' => '93'],
        ])->assertRedirect();

        $thesis = $case->theses()->sole();
        $precedent = $case->precedents()->sole();

        $this->assertSame(LegalThesisType::Preliminary, $thesis->type);
        $this->assertSame(['Súmula 393 do STJ', 'Lei nº 6.830/80'], $thesis->citedLegalBases());

        // A chave real da tese, e não o uuid que o navegador cunhou.
        $this->assertNotSame(self::UUID_A, $thesis->id);
        $this->assertSame($thesis->id, $precedent->legal_thesis_id);
        $this->assertSame('93.00', $precedent->adherence);
    }

    /**
     * The bug the map exists to avoid: two brand-new theses both keying on the
     * empty string, the second overwriting the first, and the first deleted by
     * `whereNotIn` microseconds after being created.
     */
    #[Test]
    public function two_theses_posted_without_ids_both_survive(): void
    {
        [, $owner, $case] = $this->pleading();

        $this->save($owner, $case, [
            ['id' => null, 'name' => 'Primeira tese', 'description' => 'A primeira.'],
            ['id' => null, 'name' => 'Segunda tese', 'description' => 'A segunda.'],
        ], []);

        $this->assertSame(2, $case->theses()->count());
        $this->assertSame(
            ['Primeira tese', 'Segunda tese'],
            $case->theses()->pluck('name')->all(),
        );
    }

    #[Test]
    public function a_thesis_id_from_another_account_grounds_nothing_and_touches_nothing(): void
    {
        [, $owner, $case] = $this->pleading();

        [$otherAccount] = $this->accountWithOwner();
        $foreign = LegalThesis::factory()
            ->forLegalCase(LegalCase::factory()->forAccount($otherAccount)->create())
            ->create(['name' => 'Tese de outra conta']);

        // O banco aceitaria esta chave sem reclamar: uma foreign key confere
        // existência, não propriedade. Quem recusa é o mapa.
        $this->save($owner, $case, [], [
            ['id' => null, 'legal_thesis_id' => $foreign->id, 'name' => 'STJ — Súmula nº 430/STJ', 'description' => 'O inadimplemento não gera responsabilidade solidária.'],
        ]);

        $this->assertNull($case->precedents()->sole()->legal_thesis_id);
        $this->assertSame(
            'Tese de outra conta',
            LegalThesis::acrossAllAccounts()->findOrFail($foreign->id)->name,
        );
        $this->assertSame(0, $foreign->precedents()->count());
    }

    #[Test]
    public function a_thesis_id_from_a_sibling_pleading_grounds_nothing(): void
    {
        [$account, $owner, $case] = $this->pleading();

        $sibling = LegalCase::factory()->forAccount($account)->create();
        $theirs = LegalThesis::factory()->forLegalCase($sibling)->create();

        $this->save($owner, $case, [], [
            ['id' => null, 'legal_thesis_id' => $theirs->id, 'name' => 'STJ — Súmula nº 435/STJ', 'description' => 'Presume-se dissolvida irregularmente a empresa.'],
        ]);

        $this->assertNull($case->precedents()->sole()->legal_thesis_id);
        $this->assertSame(0, $theirs->precedents()->count());
    }

    #[Test]
    public function removing_a_thesis_unhooks_its_precedent_and_keeps_it(): void
    {
        [, $owner, $case] = $this->pleading();

        $this->save($owner, $case, [
            ['id' => self::UUID_A, 'name' => 'Tese que vai sair', 'description' => 'Enquanto dura.'],
        ], [
            ['id' => self::UUID_B, 'legal_thesis_id' => self::UUID_A, 'name' => 'STJ — Súmula nº 393/STJ', 'description' => 'A exceção é admissível.'],
        ]);

        $precedent = $case->precedents()->sole();
        $this->assertNotNull($precedent->legal_thesis_id);

        // A tese sai da lista; o precedente é repostado com o mesmo palpite.
        $this->save($owner, $case, [], [
            ['id' => $precedent->id, 'legal_thesis_id' => self::UUID_A, 'name' => 'STJ — Súmula nº 393/STJ', 'description' => 'A exceção é admissível.'],
        ]);

        // O soft delete não dispara o nullOnDelete: é o mapa que desliga.
        $this->assertSame(0, $case->theses()->count());
        $this->assertNull($case->precedents()->sole()->legal_thesis_id);
    }

    #[Test]
    public function rows_that_stay_are_updated_rather_than_duplicated(): void
    {
        [, $owner, $case] = $this->pleading();

        $this->save($owner, $case, [
            ['id' => self::UUID_A, 'name' => 'Da Prescrição', 'description' => 'Primeira redação.'],
        ], []);

        $thesis = $case->theses()->sole();

        $this->save($owner, $case, [
            ['id' => $thesis->id, 'name' => 'Da Prescrição Intercorrente', 'type' => 'principal_merits', 'description' => 'Segunda redação.'],
        ], []);

        $this->assertSame(1, $case->theses()->count());

        $kept = $case->theses()->sole();
        $this->assertSame($thesis->id, $kept->id);
        $this->assertSame('Da Prescrição Intercorrente', $kept->name);
        $this->assertSame(LegalThesisType::PrincipalMerits, $kept->type);
    }

    #[Test]
    public function an_id_from_another_account_creates_a_new_row_instead_of_touching_it(): void
    {
        [, $owner, $case] = $this->pleading();

        [$otherAccount] = $this->accountWithOwner();
        $foreign = LegalPrecedent::factory()
            ->forLegalCase(LegalCase::factory()->forAccount($otherAccount)->create())
            ->create(['name' => 'Precedente de outra conta']);

        $this->save($owner, $case, [], [
            ['id' => $foreign->id, 'name' => 'Tentativa de sequestro de linha', 'description' => 'Qualquer texto.'],
        ]);

        $this->assertSame(
            'Precedente de outra conta',
            LegalPrecedent::acrossAllAccounts()->findOrFail($foreign->id)->name,
        );

        $created = $case->precedents()->sole();
        $this->assertSame('Tentativa de sequestro de linha', $created->name);
        $this->assertNotSame($foreign->id, $created->id);
    }

    #[Test]
    public function both_empty_lists_clear_the_step(): void
    {
        [, $owner, $case] = $this->pleading();

        $this->save($owner, $case, [
            ['id' => null, 'name' => 'Uma tese', 'description' => 'Alguma coisa.'],
        ], [
            ['id' => null, 'name' => 'Um precedente', 'description' => 'Alguma ementa.'],
        ]);

        $this->save($owner, $case, [], []);

        $this->assertSame(0, $case->theses()->count());
        $this->assertSame(0, $case->precedents()->count());
    }

    #[Test]
    public function a_blank_row_is_ignored_rather_than_refused(): void
    {
        [, $owner, $case] = $this->pleading();

        $this->save($owner, $case, [
            ['id' => null, 'name' => '', 'description' => ''],
            ['id' => null, 'name' => 'A tese de verdade', 'description' => 'Escrita.'],
        ], [
            ['id' => null, 'name' => '   ', 'description' => ''],
        ])->assertRedirect();

        $this->assertSame('A tese de verdade', $case->theses()->sole()->name);
        $this->assertSame(0, $case->precedents()->count());
    }

    #[Test]
    public function saving_the_review_marks_the_pleading_as_having_reached_it(): void
    {
        [, $owner, $case] = $this->pleading();

        // O default da coluna, e não um atributo da factory: só volta do banco.
        $this->assertSame(LegalCaseStep::Basics, $case->refresh()->current_step);

        $this->save($owner, $case, [], []);

        $this->assertSame(LegalCaseStep::Review, $case->refresh()->current_step);
    }

    /**
     * A revisão gravada volta para a etapa 6 ao reabrir a peça.
     *
     * Era a única etapa do assistente em que recarregar a página custava
     * trabalho já feito: as teses vinham da pesquisa, viviam no `sessionStorage`
     * e morriam com a aba, de modo que a etapa 6 de uma peça que tinha teses
     * gravadas abria dizendo que não havia pesquisa nesta sessão.
     *
     * Os ids são os persistidos, que é o que faz a próxima gravação atualizar as
     * linhas em vez de trocá-las — a mesma razão de `requirements()` mandá-los.
     */
    #[Test]
    public function the_saved_review_hydrates_the_step_when_the_pleading_is_reopened(): void
    {
        [, $owner, $case] = $this->pleading();

        $this->save($owner, $case, [
            ['id' => self::UUID_A, 'name' => 'Da Prescrição Intercorrente', 'type' => 'principal_merits', 'description' => 'Transcorrido o prazo, opera-se a prescrição.', 'impact' => 'Extingue a execução.', 'legal_bases' => [
                ['type' => 'article', 'reference' => 'Art. 40 da LEF', 'source' => 'LEF'],
            ]],
        ], [
            ['id' => null, 'legal_thesis_id' => self::UUID_A, 'name' => 'STJ — REsp 1.340.553/RS', 'type' => 'repetitive_appeal', 'description' => 'Fixa o termo inicial.', 'citation' => null, 'grounding' => null, 'adherence' => '90'],
        ]);

        $thesis = $case->theses()->sole();

        $this->actingAs($owner)
            ->get(route('legal-cases.edit', $case))
            ->assertInertia(fn ($page) => $page
                ->component('legal-cases/form')
                ->where('legalCase.theses.0.id', $thesis->id)
                ->where('legalCase.theses.0.name', 'Da Prescrição Intercorrente')
                ->where('legalCase.theses.0.type', 'principal_merits')
                ->where('legalCase.theses.0.legal_bases.0.reference', 'Art. 40 da LEF')
                // O vínculo viaja achatado, que é a forma que a tela já lê.
                ->where('legalCase.precedents.0.legal_thesis_id', $thesis->id)
                ->where('legalCase.precedents.0.adherence', '90.00'));
    }

    #[Test]
    public function a_pleading_of_another_account_is_refused(): void
    {
        [, $owner] = $this->accountWithOwner();

        [$otherAccount] = $this->accountWithOwner();
        $theirs = LegalCase::factory()->forAccount($otherAccount)->create();

        // Route-model binding resolve antes do middleware de tenant: quem barra
        // é a Policy da peça, com 403 e não 404.
        $this->save($owner, $theirs, [], [])->assertForbidden();
    }

    #[Test]
    public function an_absent_list_is_refused(): void
    {
        [, $owner, $case] = $this->pleading();

        $this->actingAs($owner)
            ->put(route('legal-cases.forensic-review', $case), ['theses' => []])
            ->assertSessionHasErrors('precedents');
    }

    private const string UUID_A = '11111111-1111-4111-8111-111111111111';

    private const string UUID_B = '22222222-2222-4222-8222-222222222222';

    /**
     * @return array{0: Account, 1: User, 2: LegalCase}
     */
    private function pleading(): array
    {
        [$account, $owner] = $this->accountWithOwner();
        $case = LegalCase::factory()->forAccount($account)->create();

        return [$account, $owner, $case];
    }

    /**
     * @param  list<array<string, mixed>>  $theses
     * @param  list<array<string, mixed>>  $precedents
     * @return TestResponse<Response>
     */
    private function save(User $owner, LegalCase $case, array $theses, array $precedents): TestResponse
    {
        // Sem actingAsUser: o middleware de auth e o de tenant rodam de
        // verdade, como rodariam para o navegador.
        return $this->actingAs($owner)->put(
            route('legal-cases.forensic-review', $case),
            ['theses' => $theses, 'precedents' => $precedents],
        );
    }
}
