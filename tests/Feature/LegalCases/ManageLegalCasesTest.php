<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Domain\Customers\Models\Customer;
use App\Domain\JudicialSystems\Models\JudicialSystem;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
    public function a_judicial_system_still_cited_by_a_pleading_cannot_be_deleted(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $system = JudicialSystem::query()->where('slug', 'pje')->sole();
        LegalCase::factory()->forAccount($account)->create(['judicial_system_id' => $system->id]);

        // Restrict, like the area: the system is catalogue too.
        $this->expectException(QueryException::class);

        DB::table('judicial_systems')->where('id', $system->id)->delete();
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

    #[Test]
    public function a_pleading_is_born_with_a_six_character_code(): void
    {
        [$account] = $this->accountWithOwner();

        $case = LegalCase::factory()->forAccount($account)->create();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{6}$/', $case->code);
    }

    /**
     * The draw runs inside a tenant's request, where AccountScope hides every
     * other account and SoftDeletes every trashed row. A code taken in either
     * place has to be drawn again, not left for the unique index to refuse.
     */
    #[Test]
    public function a_code_taken_in_another_account_or_in_the_trash_is_drawn_again(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        [$other] = $this->accountWithOwner();

        LegalCase::factory()->forAccount($other)->create(['code' => 'AbC123']);
        LegalCase::factory()->forAccount($account)->create(['code' => 'XyZ789'])->delete();

        $customer = Customer::factory()->forAccount($account)->create();
        $this->actingAsUser($owner);

        Str::createRandomStringsUsingSequence(['AbC123', 'XyZ789', 'Qwe456']);

        try {
            $case = LegalCase::factory()->forCustomer($customer)->create();
        } finally {
            Str::createRandomStringsNormally();
        }

        $this->assertSame('Qwe456', $case->code);
    }

    /**
     * Case is part of the code: the schema keeps two that differ only in it,
     * and refuses the same one twice.
     */
    #[Test]
    public function codes_that_differ_only_in_case_are_two_pleadings(): void
    {
        [$account] = $this->accountWithOwner();

        LegalCase::factory()->forAccount($account)->create(['code' => 'abc123']);
        LegalCase::factory()->forAccount($account)->create(['code' => 'ABC123']);

        $this->expectException(QueryException::class);

        LegalCase::factory()->forAccount($account)->create(['code' => 'abc123']);
    }
}
