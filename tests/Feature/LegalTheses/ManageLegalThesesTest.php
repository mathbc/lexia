<?php

declare(strict_types=1);

namespace Tests\Feature\LegalTheses;

use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalPrecedents\Models\LegalPrecedent;
use App\Domain\LegalTheses\Actions\CreateLegalThesis;
use App\Domain\LegalTheses\Actions\DeleteLegalThesis;
use App\Domain\LegalTheses\Actions\UpdateLegalThesis;
use App\Domain\LegalTheses\Data\LegalThesisData;
use App\Domain\LegalTheses\Enums\LegalBasisType;
use App\Domain\LegalTheses\Enums\LegalThesisType;
use App\Domain\LegalTheses\Models\LegalThesis;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a pleading argues, as a row.
 *
 * These go at the model, the schema and the per-row registration Actions. The
 * step's save and its route are covered by SaveLegalCaseForensicReviewTest;
 * what is pinned down here is what holds regardless of which Action writes.
 */
final class ManageLegalThesesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_thesis_is_stamped_with_the_actors_account(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();

        $thesis = LegalThesis::query()->create([
            'legal_case_id' => $case->id,
            'name' => 'Do Cabimento da Exceção de Pré-Executividade sem Garantia do Juízo',
            'type' => LegalThesisType::Preliminary,
            'description' => 'Matéria de ordem pública que independe de dilação probatória.',
        ]);

        // Never taken from the request: BelongsToAccount fills it in.
        $this->assertSame($account->id, $thesis->account_id);
        $this->assertSame($case->id, $thesis->legalCase->id);
        $this->assertSame(LegalThesisType::Preliminary, $thesis->type);
    }

    #[Test]
    public function a_thesis_carries_no_kind_no_impact_and_no_bases_unless_it_says_so(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();

        $thesis = LegalThesis::query()->create([
            'legal_case_id' => $case->id,
            'name' => 'Da Inexigibilidade do Débito',
            'description' => 'O título não é líquido, certo e exigível.',
        ]);

        // Os três estados de "ainda não decidido", distintos de zero e de [].
        $this->assertNull($thesis->type);
        $this->assertNull($thesis->impact);
        $this->assertNull($thesis->legal_bases);
        $this->assertSame([], $thesis->citedLegalBases());
    }

    #[Test]
    public function the_legal_bases_survive_the_round_trip_through_jsonb(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();

        $thesis = LegalThesis::factory()->forLegalCase($case)->citing([
            ['type' => 'sumula', 'reference' => 'Súmula 393 do STJ', 'source' => 'STJ'],
            ['type' => 'article', 'reference' => 'Art. 803 do CPC', 'source' => 'CPC'],
            ['type' => 'statute', 'reference' => 'Lei nº 6.830/80', 'source' => null],
        ])->create();

        $bases = $thesis->refresh()->legal_bases;

        $this->assertCount(3, (array) $bases);

        // O enum é gravado pelo valor, não pelo objeto: o documento é registro
        // histórico, como a migration.
        $this->assertSame('sumula', $bases[0]['type']);
        $this->assertSame(LegalBasisType::Sumula, LegalBasisType::tryFrom($bases[0]['type']));
        $this->assertNull($bases[2]['source']);

        // A ordem em que foram escritos é a ordem em que os chips saem.
        $this->assertSame([
            'Súmula 393 do STJ',
            'Art. 803 do CPC',
            'Lei nº 6.830/80',
        ], $thesis->citedLegalBases());
    }

    #[Test]
    public function every_basis_type_reads_back_as_the_case_that_wrote_it(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();
        $thesis = LegalThesis::factory()->forLegalCase($case)->citingEveryType()->create();

        $stored = array_column((array) $thesis->refresh()->legal_bases, 'type');

        $this->assertSame(
            array_column(LegalBasisType::cases(), 'value'),
            $stored,
        );
    }

    #[Test]
    public function a_pleading_reads_back_only_its_own_theses(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();
        $sibling = LegalCase::factory()->forAccount($account)->create();

        LegalThesis::factory()->forLegalCase($case)->count(2)->create();
        LegalThesis::factory()->forLegalCase($sibling)->create();

        $this->assertSame(2, $case->theses()->count());
        $this->assertSame(1, $sibling->theses()->count());
    }

    #[Test]
    public function theses_read_back_in_the_order_they_were_written(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();

        $first = LegalThesis::factory()->forLegalCase($case)->nth(0)
            ->create(['created_at' => now()->subMinute()]);
        $second = LegalThesis::factory()->forLegalCase($case)->nth(1)
            ->create(['created_at' => now()]);

        $this->assertSame(
            [$first->id, $second->id],
            $case->theses()->pluck('id')->all(),
        );
    }

    #[Test]
    public function a_thesis_is_only_visible_inside_its_own_tenant(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        [$otherAccount] = $this->accountWithOwner();

        LegalThesis::factory()
            ->forLegalCase(LegalCase::factory()->forAccount($account)->create())
            ->create();
        LegalThesis::factory()
            ->forLegalCase(LegalCase::factory()->forAccount($otherAccount)->create())
            ->create();

        $this->actingAsUser($owner);

        $this->assertSame(1, LegalThesis::query()->count());
        $this->assertSame(2, LegalThesis::acrossAllAccounts()->count());

        app(TenantContext::class)->clear();
    }

    #[Test]
    public function erasing_a_pleading_erases_what_it_argued(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();
        LegalThesis::factory()->forLegalCase($case)->count(2)->create();

        // Direto na tabela: o SoftDeletes do model nunca chega à foreign key,
        // e é a cascata de verdade que está sendo testada.
        DB::table('legal_cases')->where('id', $case->id)->delete();

        $this->assertSame(0, LegalThesis::acrossAllAccounts()->count());
    }

    #[Test]
    public function registering_a_thesis_takes_its_account_from_the_pleading(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();

        $thesis = CreateLegalThesis::run($case, LegalThesisData::fromArray([
            // O id postado é palpite: a Action não o cola na linha.
            'id' => '11111111-1111-4111-8111-111111111111',
            'name' => 'Da Ilegitimidade Passiva do Sócio-Administrador',
            'type' => 'preliminary',
            'description' => 'O simples inadimplemento não enseja o redirecionamento.',
            'impact' => 'Afasta a responsabilidade patrimonial pessoal.',
            'legal_bases' => [
                ['type' => 'article', 'reference' => 'Art. 135, III, do CTN', 'source' => 'CTN'],
                ['type' => 'sumula', 'reference' => '', 'source' => 'STJ'],
            ],
        ]));

        $this->assertSame($account->id, $thesis->account_id);
        $this->assertSame($case->id, $thesis->legal_case_id);
        $this->assertNotSame('11111111-1111-4111-8111-111111111111', $thesis->id);
        $this->assertSame(LegalThesisType::Preliminary, $thesis->type);

        // O fundamento em branco não virou item.
        $this->assertSame(['Art. 135, III, do CTN'], $thesis->citedLegalBases());
    }

    #[Test]
    public function rewriting_a_thesis_leaves_its_keys_alone(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();
        $thesis = LegalThesis::factory()->forLegalCase($case)->create();

        $updated = UpdateLegalThesis::run($thesis, LegalThesisData::fromArray([
            'name' => 'Da Prescrição Intercorrente',
            'type' => 'principal_merits',
            'description' => 'Transcorridos cinco anos do arquivamento, o crédito está prescrito.',
        ]));

        $this->assertSame('Da Prescrição Intercorrente', $updated->name);
        $this->assertSame(LegalThesisType::PrincipalMerits, $updated->type);
        $this->assertNull($updated->impact);
        $this->assertSame([], $updated->citedLegalBases());

        // Nem a chave nem as duas colunas de propriedade se movem num update.
        $this->assertSame($thesis->id, $updated->id);
        $this->assertSame($account->id, $updated->account_id);
        $this->assertSame($case->id, $updated->legal_case_id);
    }

    #[Test]
    public function deleting_a_thesis_unhooks_its_precedents_and_keeps_them(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();
        $thesis = LegalThesis::factory()->forLegalCase($case)->create();
        $precedent = LegalPrecedent::factory()->forThesis($thesis)->create();

        DeleteLegalThesis::run($thesis);

        // O soft delete não consulta a foreign key, então o desligamento é da
        // Action e não do banco — sem ele o precedente apontaria para uma tese
        // que ninguém mais enxerga.
        $this->assertNull($precedent->refresh()->legal_thesis_id);
        $this->assertSame(0, $case->theses()->count());
        $this->assertSame(1, $case->precedents()->count());
    }

    #[Test]
    public function hard_deleting_a_thesis_nulls_the_foreign_key_at_the_database(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();
        $thesis = LegalThesis::factory()->forLegalCase($case)->create();
        $precedent = LegalPrecedent::factory()->forThesis($thesis)->create();

        // A outra metade: aqui a constraint é consultada de verdade, e o
        // `nullOnDelete` é o que impede a execução de levar o julgado junto.
        $thesis->forceDelete();

        $this->assertNull($precedent->refresh()->legal_thesis_id);
        $this->assertSame(1, $case->precedents()->count());
    }
}
