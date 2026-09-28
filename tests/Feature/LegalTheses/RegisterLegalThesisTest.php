<?php

declare(strict_types=1);

namespace Tests\Feature\LegalTheses;

use App\Domain\Accounts\Models\Account;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalTheses\Enums\LegalBasisType;
use App\Domain\LegalTheses\Enums\LegalThesisOrigin;
use App\Domain\LegalTheses\Enums\LegalThesisType;
use App\Domain\LegalTheses\Models\LegalThesis;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The thesis the research did not bring: "Cadastrar tese" and "Editar" on step 5.
 *
 * What is pinned here is the route around CreateLegalThesis and
 * UpdateLegalThesis — the row is written at once and stamped manual, the author
 * cannot be forged from the payload, and only a manual thesis of *this* pleading
 * can be rewritten. What survives a new research is ResearchLegalCaseForensic
 * ReviewTest's; what the "Concluir" does with it is FinalizeLegalCaseTest's.
 */
final class RegisterLegalThesisTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function registering_writes_a_manual_thesis_and_returns_to_the_step(): void
    {
        [, $owner, $case] = $this->pleading();

        $this->store($owner, $case, $this->thesis())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('legal-cases.edit', [
                'legalCase' => $case,
                'etapa' => LegalCaseStep::Review->value,
            ]));

        $thesis = $case->theses()->sole();

        $this->assertSame('Da Nulidade da Certidão de Dívida Ativa', $thesis->name);
        $this->assertSame(LegalThesisType::Preliminary, $thesis->type);
        $this->assertSame(LegalThesisOrigin::Manual, $thesis->origin);
        $this->assertSame($case->account_id, $thesis->account_id);

        // A tabela chega como a coluna guarda: tipo no valor do enum, a fonte
        // ausente como null, e a referência exatamente como foi digitada.
        // `assertEquals` porque o jsonb reordena as chaves de cada objeto.
        $this->assertEquals([
            ['type' => 'article', 'reference' => 'Art. 202 do CTN', 'source' => 'CTN'],
            ['type' => null, 'reference' => 'Art. 2º, § 5º, da Lei nº 6.830/80', 'source' => null],
        ], $thesis->legal_bases);
    }

    #[Test]
    public function the_author_cannot_be_forged_from_the_payload(): void
    {
        [, $owner, $case] = $this->pleading();

        $this->store($owner, $case, [...$this->thesis(), 'origin' => LegalThesisOrigin::Ai->value])
            ->assertSessionHasNoErrors();

        $this->assertSame(LegalThesisOrigin::Manual, $case->theses()->sole()->origin);
    }

    #[Test]
    public function the_heading_the_kind_and_the_argument_are_required(): void
    {
        [, $owner, $case] = $this->pleading();

        $this->store($owner, $case, ['name' => '', 'type' => '', 'description' => ''])
            ->assertSessionHasErrors(['name', 'type', 'description']);

        $this->assertSame(0, $case->theses()->count());
    }

    /**
     * Um fundamento preenchido pela metade é um engano a apontar, e não uma
     * linha a descartar em silêncio — a tela manda as totalmente em branco
     * embora antes de postar.
     */
    #[Test]
    public function a_basis_needs_a_reference_a_known_kind_and_a_short_source(): void
    {
        [, $owner, $case] = $this->pleading();

        $this->store($owner, $case, [...$this->thesis(), 'legal_bases' => [
            ['type' => 'article', 'reference' => '', 'source' => 'CTN'],
            ['type' => 'doctrine', 'reference' => 'Art. 1º', 'source' => null],
            ['type' => null, 'reference' => 'Art. 2º', 'source' => str_repeat('X', 61)],
        ]])->assertSessionHasErrors([
            'legal_bases.0.reference',
            'legal_bases.1.type',
            'legal_bases.2.source',
        ]);

        $this->assertSame(0, $case->theses()->count());
    }

    #[Test]
    public function a_pleading_of_another_account_is_refused(): void
    {
        [, $owner] = $this->accountWithOwner();

        [$otherAccount] = $this->accountWithOwner();
        $theirs = LegalCase::factory()->forAccount($otherAccount)->create();

        $this->store($owner, $theirs, $this->thesis())->assertForbidden();

        $this->assertSame(0, LegalThesis::acrossAllAccounts()->count());
    }

    #[Test]
    public function a_manual_thesis_can_be_rewritten(): void
    {
        [, $owner, $case] = $this->pleading();
        $thesis = LegalThesis::factory()->forLegalCase($case)->manual()->create();

        $this->update($owner, $case, $thesis, [
            ...$this->thesis(),
            'name' => 'Da Prescrição Intercorrente',
            'type' => 'principal_merits',
            'legal_bases' => [],
        ])->assertSessionHasNoErrors()
            ->assertRedirect(route('legal-cases.edit', [
                'legalCase' => $case,
                'etapa' => LegalCaseStep::Review->value,
            ]));

        $thesis->refresh();

        $this->assertSame('Da Prescrição Intercorrente', $thesis->name);
        $this->assertSame(LegalThesisType::PrincipalMerits, $thesis->type);
        $this->assertSame([], $thesis->legal_bases);
        $this->assertSame(LegalThesisOrigin::Manual, $thesis->origin);
    }

    /**
     * A tese da pesquisa é leitura de um portal oficial: reescrevê-la deixaria
     * uma citação que não diz mais o que o portal disse. Quem discorda dela a
     * desmarca.
     */
    #[Test]
    public function a_researched_thesis_cannot_be_rewritten(): void
    {
        [, $owner, $case] = $this->pleading();
        $thesis = LegalThesis::factory()->forLegalCase($case)->create();

        $this->update($owner, $case, $thesis, $this->thesis())->assertForbidden();

        $this->assertNotSame('Da Nulidade da Certidão de Dívida Ativa', $thesis->refresh()->name);
    }

    /**
     * A URL carrega dois modelos, e a tese tem de ser da peça que ela nomeia:
     * senão uma peça que o ator pode escrever serviria de passe para a tese da
     * peça irmã.
     */
    #[Test]
    public function a_thesis_of_a_sibling_pleading_cannot_be_rewritten_through_this_one(): void
    {
        [$account, $owner, $case] = $this->pleading();

        $sibling = LegalCase::factory()->forAccount($account)->create();
        $theirs = LegalThesis::factory()->forLegalCase($sibling)->manual()->create();

        $this->update($owner, $case, $theirs, $this->thesis())->assertForbidden();

        $this->assertNotSame('Da Nulidade da Certidão de Dívida Ativa', $theirs->refresh()->name);
    }

    /**
     * Route-model binding resolve a tese antes do middleware de tenant, então
     * ela chega resolvida — quem barra é a Policy, com 403 e não 404.
     */
    #[Test]
    public function a_thesis_of_another_account_cannot_be_rewritten(): void
    {
        [, $owner] = $this->accountWithOwner();

        [$otherAccount] = $this->accountWithOwner();
        $theirCase = LegalCase::factory()->forAccount($otherAccount)->create();
        $theirs = LegalThesis::factory()->forLegalCase($theirCase)->manual()->create();

        $this->update($owner, $theirCase, $theirs, $this->thesis())->assertForbidden();
    }

    #[Test]
    public function the_step_learns_who_wrote_each_thesis_and_how_to_label_it(): void
    {
        [, $owner, $case] = $this->pleading();
        $thesis = LegalThesis::factory()->forLegalCase($case)->manual()->create();

        $this->actingAs($owner)
            ->get(route('legal-cases.edit', $case))
            ->assertInertia(fn ($page) => $page
                ->component('legal-cases/form')
                ->where('legalCase.theses.0.id', $thesis->id)
                ->where('legalCase.theses.0.origin', 'manual')
                ->where('thesisOrigins', LegalThesisOrigin::options())
                ->where('legalBasisTypes', LegalBasisType::options()));
    }

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
     * What the modal posts: the three required fields, the optional one, and a
     * two-row table where the second row left kind and source blank.
     *
     * @return array<string, mixed>
     */
    private function thesis(): array
    {
        return [
            'name' => 'Da Nulidade da Certidão de Dívida Ativa',
            'type' => 'preliminary',
            'description' => 'A CDA não indica a origem nem a natureza do crédito, requisitos do art. 202 do CTN.',
            'impact' => 'Extingue a execução sem exame do mérito.',
            'legal_bases' => [
                ['type' => 'article', 'reference' => 'Art. 202 do CTN', 'source' => 'CTN'],
                ['type' => null, 'reference' => 'Art. 2º, § 5º, da Lei nº 6.830/80', 'source' => null],
            ],
        ];
    }

    /**
     * Sem actingAsUser: o middleware de auth e o de tenant rodam de verdade,
     * como rodariam para o navegador.
     *
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function store(User $owner, LegalCase $case, array $payload): TestResponse
    {
        return $this->actingAs($owner)->post(route('legal-cases.theses.store', $case), $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function update(User $owner, LegalCase $case, LegalThesis $thesis, array $payload): TestResponse
    {
        return $this->actingAs($owner)->put(
            route('legal-cases.theses.update', ['legalCase' => $case, 'thesis' => $thesis]),
            $payload,
        );
    }
}
