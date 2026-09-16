<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Domain\Customers\Models\Customer;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The pleading as a row: an account, a client and the two CNJ keys.
 *
 * These go at the model and the schema directly, deliberately below the
 * routes — the assembly flow is covered by SaveLegalCaseStepsTest, and what is
 * pinned down here is what holds regardless of which Action does the writing.
 */
final class ManageLegalCasesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_pleading_is_stamped_with_the_actors_account(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $customer = Customer::factory()->forAccount($account)->create();
        $area = PracticeArea::query()->where('slug', 'imobiliario')->sole();
        $class = $area->proceduralClasses()->where('code', 49)->sole();

        $case = LegalCase::query()->create([
            'customer_id' => $customer->id,
            'practice_area_id' => $area->id,
            'procedural_class_id' => $class->id,
        ]);

        // Never taken from the request: BelongsToAccount fills it in.
        $this->assertSame($account->id, $case->account_id);
        $this->assertSame('Usucapião', $case->proceduralClass->name);
    }

    #[Test]
    public function the_catalogue_is_shared_by_every_account(): void
    {
        [, $owner] = $this->accountWithOwner();
        [, $stranger] = $this->accountWithOwner();

        $this->actingAsUser($owner);
        $mine = PracticeArea::query()->count();

        app(TenantContext::class)->clear();
        $this->actingAsUser($stranger);

        // Reference data carries no account_id, so nothing narrows it — two
        // firms draft against one catalogue.
        $this->assertSame(24, $mine);
        $this->assertSame($mine, PracticeArea::query()->count());
    }

    #[Test]
    public function a_pleading_is_only_visible_inside_its_own_tenant(): void
    {
        [$mine, $owner] = $this->accountWithOwner();
        LegalCase::factory()->forAccount($mine)->count(2)->create();

        [$theirs] = $this->accountWithOwner();
        LegalCase::factory()->forAccount($theirs)->count(3)->create();

        $this->actingAsUser($owner);

        $this->assertSame(2, LegalCase::query()->count());
        $this->assertSame(5, LegalCase::acrossAllAccounts()->count());
    }

    #[Test]
    public function the_factory_never_pairs_a_class_with_a_foreign_area(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        // Ten draws, because the area and the class are picked at random and a
        // single one could pass by luck.
        LegalCase::factory()->forAccount($account)->count(10)->create()
            ->each(function (LegalCase $case) use ($account): void {
                $this->assertSame($account->id, $case->customer->account_id);
                $this->assertTrue($case->proceduralClass->is_filing_class);
                $this->assertTrue(
                    $case->practiceArea->proceduralClasses()
                        ->whereKey($case->procedural_class_id)
                        ->exists(),
                );
            });
    }

    #[Test]
    public function an_area_still_cited_by_a_pleading_cannot_be_deleted(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();

        // restrictOnDelete, unlike the account and client keys: the catalogue
        // is reference data and must not vanish under a pleading that cites it.
        $this->expectException(QueryException::class);

        DB::table('practice_areas')->where('id', $case->practice_area_id)->delete();
    }

    #[Test]
    public function deleting_a_pleading_leaves_the_catalogue_alone(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();

        $case->delete();

        $this->assertSoftDeleted($case);
        $this->assertSame(24, PracticeArea::query()->count());
        $this->assertSame(615, ProceduralClass::query()->count());
    }
}
