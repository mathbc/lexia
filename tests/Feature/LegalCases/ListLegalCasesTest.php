<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Domain\Customers\Models\Customer;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ListLegalCasesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_lawyer_sees_only_the_pleadings_of_their_own_account(): void
    {
        [$account] = $this->accountWithOwner();
        $lawyer = User::factory()->forAccount($account)->create();

        LegalCase::factory()->forAccount($account)->count(3)->create();

        [$other] = $this->accountWithOwner();
        LegalCase::factory()->forAccount($other)->count(2)->create();

        $this->actingAs($lawyer)
            ->get('/pecas')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('legal-cases/index')
                ->where('legalCases.total', 3));
    }

    /**
     * Route-model binding runs before the tenant middleware and for platform
     * staff the query scope is deliberately open, so the listing has to name
     * the account itself — this is the test that would catch it leaking.
     */
    #[Test]
    public function platform_staff_do_not_see_another_accounts_pleadings(): void
    {
        [$account] = $this->accountWithOwner();
        LegalCase::factory()->forAccount($account)->count(3)->create();

        $staff = User::factory()->platformAdmin()->create();

        $this->actingAs($staff)
            ->get('/pecas')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('legalCases.total', 0));
    }

    #[Test]
    public function the_listing_filters_by_client_and_by_practice_area(): void
    {
        [$account, $owner] = $this->accountWithOwner();

        $client = Customer::factory()->forAccount($account)->create();
        $labor = PracticeArea::query()->where('slug', 'trabalhista')->sole();

        LegalCase::factory()->forCustomer($client)->inArea($labor)->create();
        LegalCase::factory()->forAccount($account)->count(2)->create();

        $this->actingAs($owner)
            ->get('/pecas?customer='.$client->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('legalCases.total', 1));

        $this->actingAs($owner)
            ->get('/pecas?practice_area=trabalhista')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('legalCases.total', 1));
    }

    #[Test]
    public function the_form_carries_the_areas_but_no_class_until_one_is_chosen(): void
    {
        [, $owner] = $this->accountWithOwner();

        $this->actingAs($owner)
            ->get('/pecas/nova')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('legal-cases/create')
                ->has('practiceAreas', 24)
                ->has('proceduralClasses', 0)
                ->where('selectedArea', ''));
    }

    #[Test]
    public function choosing_an_area_loads_its_classes_with_the_specific_ones_first(): void
    {
        [, $owner] = $this->accountWithOwner();

        $this->actingAs($owner)
            ->get('/pecas/nova?area=trabalhista')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('selectedArea', 'trabalhista')
                // Trabalhista is a branch with its own rite: 90 classes of its
                // own and none of the civil trunk.
                ->has('proceduralClasses', 90)
                ->where('proceduralClasses.0.scope', 'specific'));
    }

    #[Test]
    public function an_unknown_area_yields_no_classes_rather_than_an_error(): void
    {
        [, $owner] = $this->accountWithOwner();

        $this->actingAs($owner)
            ->get('/pecas/nova?area=nao-existe')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('proceduralClasses', 0));
    }
}
