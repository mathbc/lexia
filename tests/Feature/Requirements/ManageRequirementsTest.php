<?php

declare(strict_types=1);

namespace Tests\Feature\Requirements;

use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\Requirements\Models\Requirement;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a pleading asks the court for, at the stage it exists today: rows
 * nobody writes through a screen yet. There are no routes and no save Action,
 * so these exercise the model and the schema directly.
 */
final class ManageRequirementsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_requirement_is_stamped_with_the_actors_account(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();

        $requirement = Requirement::query()->create([
            'legal_case_id' => $case->id,
            'description' => 'A procedência total dos pedidos para condenar o Réu ao pagamento de danos materiais e morais;',
            'amount' => 50_000,
        ]);

        // Never taken from the request: BelongsToAccount fills it in.
        $this->assertSame($account->id, $requirement->account_id);
        $this->assertSame($case->id, $requirement->legalCase->id);
        $this->assertSame('50000.00', $requirement->amount);
    }

    #[Test]
    public function a_requirement_claims_no_money_unless_it_says_so(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $requirement = Requirement::factory()
            ->forLegalCase(LegalCase::factory()->forAccount($account)->create())
            ->create(['description' => 'A concessão da Gratuidade da Justiça;', 'amount' => null]);

        // Most requests are worth nothing in reais — a fee waiver, a summons —
        // and the column says so rather than storing a zero that reads as one.
        $this->assertNull($requirement->amount);
    }

    #[Test]
    public function a_pleading_reads_back_only_its_own_requirements(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();
        $other = LegalCase::factory()->forAccount($account)->create();

        Requirement::factory()->forLegalCase($case)->count(3)->create();
        Requirement::factory()->forLegalCase($other)->count(2)->create();

        $this->assertCount(3, $case->requirements);
        $this->assertCount(2, $other->requirements);
    }

    #[Test]
    public function requirements_read_back_in_the_order_they_were_written(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();

        // Spaced in time on purpose: the relation orders by `created_at`, and
        // rows written in the same second have no order between them.
        $first = Requirement::factory()->forLegalCase($case)->create([
            'description' => 'A concessão da Gratuidade da Justiça;',
            'created_at' => now()->subMinutes(2),
        ]);
        $second = Requirement::factory()->forLegalCase($case)->create([
            'description' => 'A citação do Réu;',
            'created_at' => now()->subMinute(),
        ]);

        $this->assertSame(
            [$first->id, $second->id],
            $case->requirements->pluck('id')->all(),
        );
    }

    #[Test]
    public function a_requirement_is_only_visible_inside_its_own_tenant(): void
    {
        [$mine, $owner] = $this->accountWithOwner();
        Requirement::factory()
            ->forLegalCase(LegalCase::factory()->forAccount($mine)->create())
            ->count(2)
            ->create();

        [$theirs] = $this->accountWithOwner();
        Requirement::factory()
            ->forLegalCase(LegalCase::factory()->forAccount($theirs)->create())
            ->count(3)
            ->create();

        app(TenantContext::class)->clear();
        $this->actingAsUser($owner);

        $this->assertSame(2, Requirement::query()->count());
        $this->assertSame(5, Requirement::acrossAllAccounts()->count());
    }

    #[Test]
    public function erasing_a_pleading_erases_what_it_asked_for(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();
        Requirement::factory()->forLegalCase($case)->count(2)->create();

        // Straight to the table, past SoftDeletes: what is under test is the
        // cascade the foreign key declares, and a soft delete never reaches it.
        DB::table('legal_cases')->where('id', $case->id)->delete();

        $this->assertSame(0, DB::table('requirements')->count());
    }
}
