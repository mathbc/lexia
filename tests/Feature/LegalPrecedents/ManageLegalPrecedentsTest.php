<?php

declare(strict_types=1);

namespace Tests\Feature\LegalPrecedents;

use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalPrecedents\Actions\CreateLegalPrecedent;
use App\Domain\LegalPrecedents\Actions\DeleteLegalPrecedent;
use App\Domain\LegalPrecedents\Actions\UpdateLegalPrecedent;
use App\Domain\LegalPrecedents\Data\LegalPrecedentData;
use App\Domain\LegalPrecedents\Enums\LegalPrecedentType;
use App\Domain\LegalPrecedents\Models\LegalPrecedent;
use App\Domain\LegalTheses\Models\LegalThesis;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The rulings that sustain what a pleading argues, as rows.
 *
 * The model, the schema and the per-row registration Actions. The step's save
 * and the id map that resolves `legal_thesis_id` are covered by
 * SaveLegalCaseForensicReviewTest.
 */
final class ManageLegalPrecedentsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_precedent_is_stamped_with_the_actors_account(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();

        $precedent = LegalPrecedent::query()->create([
            'legal_case_id' => $case->id,
            'name' => 'STJ — Súmula nº 393/STJ',
            'type' => LegalPrecedentType::Sumula,
            'description' => 'A exceção de pré-executividade é admissível na execução fiscal.',
            'adherence' => 93,
        ]);

        $this->assertSame($account->id, $precedent->account_id);
        $this->assertSame($case->id, $precedent->legalCase->id);
        $this->assertSame(LegalPrecedentType::Sumula, $precedent->type);
        $this->assertSame('93.00', $precedent->adherence);
    }

    #[Test]
    public function a_precedent_was_scored_by_nobody_unless_it_says_so(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();

        $precedent = LegalPrecedent::factory()->forLegalCase($case)->unscored()->create();

        // Nulo e não zero: aderência é medida que alguém fez, não propriedade
        // da súmula, e um zero diria que foi medida e julgada irrelevante.
        $this->assertNull($precedent->refresh()->adherence);
    }

    #[Test]
    public function a_precedent_grounds_nothing_until_a_thesis_is_named(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();
        $precedent = LegalPrecedent::factory()->forLegalCase($case)->create();

        $this->assertNull($precedent->legal_thesis_id);
        $this->assertNull($precedent->thesis);

        // E continua sendo um achado desta peça mesmo sem tese.
        $this->assertSame(1, $case->precedents()->count());
    }

    #[Test]
    public function a_thesis_reads_back_the_precedents_that_sustain_it(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();
        $thesis = LegalThesis::factory()->forLegalCase($case)->create();
        $other = LegalThesis::factory()->forLegalCase($case)->create();

        LegalPrecedent::factory()->forThesis($thesis)->count(2)->create();
        LegalPrecedent::factory()->forThesis($other)->create();
        LegalPrecedent::factory()->forLegalCase($case)->create();

        $this->assertSame(2, $thesis->precedents()->count());
        $this->assertSame(1, $other->precedents()->count());

        // A peça vê todos, inclusive o que não fundamenta tese nenhuma.
        $this->assertSame(4, $case->precedents()->count());
    }

    #[Test]
    public function precedents_read_back_in_the_order_they_were_written(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();

        $first = LegalPrecedent::factory()->forLegalCase($case)->nth(0)
            ->create(['created_at' => now()->subMinute()]);
        $second = LegalPrecedent::factory()->forLegalCase($case)->nth(1)
            ->create(['created_at' => now()]);

        $this->assertSame(
            [$first->id, $second->id],
            $case->precedents()->pluck('id')->all(),
        );
    }

    #[Test]
    public function a_precedent_is_only_visible_inside_its_own_tenant(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        [$otherAccount] = $this->accountWithOwner();

        LegalPrecedent::factory()
            ->forLegalCase(LegalCase::factory()->forAccount($account)->create())
            ->create();
        LegalPrecedent::factory()
            ->forLegalCase(LegalCase::factory()->forAccount($otherAccount)->create())
            ->create();

        $this->actingAsUser($owner);

        $this->assertSame(1, LegalPrecedent::query()->count());
        $this->assertSame(2, LegalPrecedent::acrossAllAccounts()->count());

        app(TenantContext::class)->clear();
    }

    #[Test]
    public function erasing_a_pleading_erases_what_sustained_it(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();
        $thesis = LegalThesis::factory()->forLegalCase($case)->create();
        LegalPrecedent::factory()->forThesis($thesis)->create();
        LegalPrecedent::factory()->forLegalCase($case)->create();

        DB::table('legal_cases')->where('id', $case->id)->delete();

        $this->assertSame(0, LegalPrecedent::acrossAllAccounts()->count());
    }

    #[Test]
    public function registering_a_precedent_takes_its_account_from_the_pleading(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();
        $thesis = LegalThesis::factory()->forLegalCase($case)->create();

        $precedent = CreateLegalPrecedent::run($case, LegalPrecedentData::fromArray([
            'name' => 'STJ — Súmula nº 430/STJ',
            'type' => 'sumula',
            'description' => 'O inadimplemento da obrigação tributária pela sociedade não gera, por si só, a responsabilidade solidária do sócio-administrador.',
            'citation' => 'BRASIL. Superior Tribunal de Justiça. Súmula nº 430. Primeira Seção. Rel. Min. Luiz Fux, julgado em 25/11/2009, DJe 15/12/2009.',
            'grounding' => 'Fundamenta a ilegitimidade passiva do sócio-administrador.',
            'adherence' => '93%',
        ]), $thesis);

        $this->assertSame($account->id, $precedent->account_id);
        $this->assertSame($case->id, $precedent->legal_case_id);
        $this->assertSame($thesis->id, $precedent->legal_thesis_id);
        $this->assertSame('93.00', $precedent->adherence);
        $this->assertSame(LegalPrecedentType::Sumula, $precedent->type);
    }

    #[Test]
    public function a_thesis_from_another_pleading_grounds_nothing(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();
        $sibling = LegalCase::factory()->forAccount($account)->create();
        $theirs = LegalThesis::factory()->forLegalCase($sibling)->create();

        // Resolver o model certo e resolver o model *desta peça* são coisas
        // diferentes: a tese da peça irmã passa por todo guarda de tenant.
        $precedent = CreateLegalPrecedent::run($case, LegalPrecedentData::fromArray([
            'name' => 'STJ — Súmula nº 435/STJ',
            'description' => 'Presume-se dissolvida irregularmente a empresa que deixar de funcionar no seu domicílio fiscal.',
        ]), $theirs);

        $this->assertNull($precedent->legal_thesis_id);
        $this->assertSame(0, $theirs->precedents()->count());
    }

    #[Test]
    public function rewriting_a_precedent_can_detach_it_from_its_thesis(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();
        $thesis = LegalThesis::factory()->forLegalCase($case)->create();
        $precedent = LegalPrecedent::factory()->forThesis($thesis)->create();

        $data = LegalPrecedentData::fromArray([
            'name' => 'STJ — Tema Repetitivo nº 981',
            'type' => 'repetitive_appeal',
            'description' => 'O redirecionamento ao sócio que se retirou antes da dissolução irregular é indevido.',
        ]);

        // A tese é parâmetro da chamada e não propriedade do dado: não passá-la
        // desliga, que é a leitura honesta de um update que escreve a linha
        // inteira.
        $detached = UpdateLegalPrecedent::run($precedent, $data);

        $this->assertNull($detached->legal_thesis_id);
        $this->assertSame(LegalPrecedentType::RepetitiveAppeal, $detached->type);
        $this->assertNull($detached->adherence);

        $reattached = UpdateLegalPrecedent::run($detached, $data, $thesis);

        $this->assertSame($thesis->id, $reattached->legal_thesis_id);
        $this->assertSame($precedent->id, $reattached->id);
        $this->assertSame($account->id, $reattached->account_id);
    }

    #[Test]
    public function deleting_a_precedent_leaves_its_thesis_standing(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();
        $thesis = LegalThesis::factory()->forLegalCase($case)->create();
        $precedent = LegalPrecedent::factory()->forThesis($thesis)->create();

        DeleteLegalPrecedent::run($precedent);

        $this->assertSame(0, $thesis->precedents()->count());
        $this->assertSame(1, $case->theses()->count());
    }
}
