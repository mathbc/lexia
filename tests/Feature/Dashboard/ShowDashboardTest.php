<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Domain\Accounts\Models\Account;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ShowDashboardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_lawyer_sees_the_indicators_of_their_own_account_only(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $lawyer = User::factory()->forAccount($account)->create();

        LegalCase::factory()->by($owner)->finalised()->count(2)->create();
        LegalCase::factory()->by($lawyer)->draft()->count(3)->create();

        [, $stranger] = $this->accountWithOwner();
        LegalCase::factory()->by($stranger)->finalised()->count(4)->create();

        $this->actingAs($lawyer)
            ->get('/painel')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->where('indicators.finalized', 2)
                ->where('indicators.drafts', 3)
                ->where('indicators.users', 2)
                ->where('platform', null)
                ->where('accounts', [])
                ->where('can.view_accounts', false));
    }

    /**
     * The URL is the only thing a customer controls here, and the account in
     * it must not move the boundary.
     */
    #[Test]
    public function a_customer_cannot_look_at_another_account_through_the_url(): void
    {
        [, $owner] = $this->accountWithOwner();
        LegalCase::factory()->by($owner)->count(1)->create();

        [$other, $stranger] = $this->accountWithOwner();
        LegalCase::factory()->by($stranger)->count(5)->create();

        $this->actingAs($owner)
            ->get('/painel?account='.$other->id.'&user='.$stranger->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('indicators.drafts', 1)
                ->where('filters', [])
                ->where('users', [['value' => $owner->id, 'label' => $owner->name]]));

        // Staff may pick the LexIA account; a customer asking for it stays home.
        $staff = User::factory()->platformAdmin()->create();
        LegalCase::factory()->by($staff)->count(2)->create();

        $this->actingAs($owner)
            ->get('/painel?account='.Account::PLATFORM_ID.'&user='.$staff->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('indicators.drafts', 1)
                ->where('filters', [])
                ->where('accounts', []));
    }

    #[Test]
    public function staff_see_the_platform_card_with_the_platform_account_in_it(): void
    {
        [, $first] = $this->accountWithOwner();
        User::factory()->forAccount($first->account)->create();
        LegalCase::factory()->by($first)->finalised()->count(2)->create();

        [, $second] = $this->accountWithOwner();
        LegalCase::factory()->by($second)->draft()->create();

        $staff = User::factory()->platformAdmin()->create();
        User::factory()->platformAdmin()->create();
        LegalCase::factory()->by($staff)->draft()->create();

        // Two customer accounts plus the platform one, which counts like any
        // other: its type only decides who may cross the boundary.
        $this->actingAs($staff)
            ->get('/painel')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.view_accounts', true)
                ->where('platform.accounts', 3)
                ->where('platform.legal_cases', 4)
                ->where('platform.users', 5)
                ->where('indicators.finalized', 2)
                ->where('indicators.drafts', 2)
                ->where('indicators.users', 5)
                ->has('accounts', 3)
                ->where('accounts', fn ($accounts) => $accounts->pluck('value')->contains(Account::PLATFORM_ID))
                ->where('users', []));
    }

    #[Test]
    public function staff_choose_the_platform_account_and_the_chart_follows_it(): void
    {
        [, $owner] = $this->accountWithOwner();
        LegalCase::factory()->by($owner)->draft()->count(3)->create(['created_at' => '2026-04-10 10:00:00']);

        $staff = User::factory()->platformAdmin()->create();
        $colleague = User::factory()->platformAdmin()->create();
        LegalCase::factory()->by($staff)->finalised()->create(['created_at' => '2026-04-11 10:00:00']);
        LegalCase::factory()->by($colleague)->draft()->create(['created_at' => '2026-04-12 10:00:00']);

        // Every account at once: the staff's own pleadings are in the bars.
        $this->actingAs($staff)
            ->get('/painel')
            ->assertInertia(fn ($page) => $page
                ->where('activity.months.3', ['month' => 4, 'finalized' => 1, 'drafts' => 4]));

        $this->actingAs($staff)
            ->get('/painel?account='.Account::PLATFORM_ID)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.account', Account::PLATFORM_ID)
                ->where('indicators.finalized', 1)
                ->where('indicators.drafts', 1)
                ->where('indicators.users', 2)
                ->where('activity.months.3', ['month' => 4, 'finalized' => 1, 'drafts' => 1])
                ->where('users', fn ($users) => $users->pluck('value')->sort()->values()->all()
                    === collect([$staff->id, $colleague->id])->sort()->values()->all()));

        $this->actingAs($staff)
            ->get('/painel?account='.Account::PLATFORM_ID.'&user='.$colleague->id)
            ->assertInertia(fn ($page) => $page
                ->where('filters.user', $colleague->id)
                ->where('activity.months.3', ['month' => 4, 'finalized' => 0, 'drafts' => 1]));
    }

    #[Test]
    public function staff_narrow_the_cards_and_the_user_list_to_the_chosen_account(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $lawyer = User::factory()->forAccount($account)->create();
        LegalCase::factory()->by($lawyer)->finalised()->create();

        [, $other] = $this->accountWithOwner();
        LegalCase::factory()->by($other)->draft()->count(3)->create();

        $staff = User::factory()->platformAdmin()->create();

        $this->actingAs($staff)
            ->get('/painel?account='.$account->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.account', $account->id)
                ->where('indicators.finalized', 1)
                ->where('indicators.drafts', 0)
                ->where('indicators.users', 2)
                ->where('users', fn ($users) => $users->pluck('value')->sort()->values()->all()
                    === collect([$owner->id, $lawyer->id])->sort()->values()->all()));
    }

    #[Test]
    public function staff_cannot_choose_a_deleted_account_nor_a_malformed_id(): void
    {
        $staff = User::factory()->platformAdmin()->create();

        [$deleted] = $this->accountWithOwner();
        $deleted->delete();

        // Refused, the filter falls back to every account — the staff member
        // alone, since the deleted one takes its owner out of the sums.
        foreach ([$deleted->id, 'not-a-uuid'] as $account) {
            $this->actingAs($staff)
                ->get('/painel?account='.$account.'&user=nope')
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('filters', [])
                    ->where('users', [])
                    ->has('accounts', 1)
                    ->where('indicators.users', 1));
        }
    }

    #[Test]
    public function the_year_filter_offers_only_years_with_pleadings_and_defaults_to_the_newest(): void
    {
        [, $owner] = $this->accountWithOwner();
        LegalCase::factory()->by($owner)->create(['created_at' => '2024-05-02 10:00:00']);
        LegalCase::factory()->by($owner)->create(['created_at' => '2026-02-11 10:00:00']);

        [, $stranger] = $this->accountWithOwner();
        LegalCase::factory()->by($stranger)->create(['created_at' => '2025-07-01 10:00:00']);

        $this->actingAs($owner)
            ->get('/painel')
            ->assertInertia(fn ($page) => $page
                ->where('years', [2026, 2024])
                ->where('activity.year', 2026)
                ->where('filters', []));

        // A year the account has no pleading in is not an active filter.
        $this->actingAs($owner)
            ->get('/painel?year=2025')
            ->assertInertia(fn ($page) => $page
                ->where('activity.year', 2026)
                ->where('filters', []));

        $this->actingAs($owner)
            ->get('/painel?year=2024')
            ->assertInertia(fn ($page) => $page
                ->where('activity.year', 2024)
                ->where('filters.year', 2024)
                ->where('activity.months.4.drafts', 1));
    }

    #[Test]
    public function the_chart_has_twelve_months_split_by_where_each_pleading_stands(): void
    {
        [, $owner] = $this->accountWithOwner();
        LegalCase::factory()->by($owner)->finalised()->create(['created_at' => '2026-03-01 00:00:00']);
        LegalCase::factory()->by($owner)->draft()->create(['created_at' => '2026-03-31 23:59:59']);
        LegalCase::factory()->by($owner)->draft()->create(['created_at' => '2026-07-15 12:00:00']);
        // The neighbouring years stay out of the bars.
        LegalCase::factory()->by($owner)->draft()->create(['created_at' => '2025-12-31 23:59:59']);
        LegalCase::factory()->by($owner)->draft()->create(['created_at' => '2027-01-01 00:00:00']);

        $this->actingAs($owner)
            ->get('/painel?year=2026')
            ->assertInertia(fn ($page) => $page
                ->has('activity.months', 12)
                ->where('activity.months.0', ['month' => 1, 'finalized' => 0, 'drafts' => 0])
                ->where('activity.months.2', ['month' => 3, 'finalized' => 1, 'drafts' => 1])
                ->where('activity.months.6', ['month' => 7, 'finalized' => 0, 'drafts' => 1])
                ->where('activity.months.11', ['month' => 12, 'finalized' => 0, 'drafts' => 0]));
    }

    #[Test]
    public function the_user_filter_narrows_the_chart_to_the_pleadings_that_user_opened(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $lawyer = User::factory()->forAccount($account)->create();

        LegalCase::factory()->by($owner)->draft()->count(2)->create(['created_at' => '2026-04-10 10:00:00']);
        LegalCase::factory()->by($lawyer)->draft()->create(['created_at' => '2026-04-12 10:00:00']);
        // Older than the column: counted in the account, never under a name.
        LegalCase::factory()->forAccount($account)->draft()->create(['created_at' => '2026-04-20 10:00:00']);

        $this->actingAs($owner)
            ->get('/painel')
            ->assertInertia(fn ($page) => $page->where('activity.months.3.drafts', 4));

        $this->actingAs($owner)
            ->get('/painel?user='.$lawyer->id)
            ->assertInertia(fn ($page) => $page
                ->where('filters.user', $lawyer->id)
                ->where('activity.months.3.drafts', 1)
                // The cards are the account's, whoever is picked in the chart.
                ->where('indicators.drafts', 4));
    }

    #[Test]
    public function a_user_from_another_account_or_a_malformed_id_filters_nothing(): void
    {
        [, $owner] = $this->accountWithOwner();
        LegalCase::factory()->by($owner)->draft()->count(2)->create(['created_at' => '2026-04-10 10:00:00']);

        [, $stranger] = $this->accountWithOwner();

        foreach ([$stranger->id, 'not-a-uuid'] as $user) {
            $this->actingAs($owner)
                ->get('/painel?user='.$user)
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('filters', [])
                    ->where('activity.months.3.drafts', 2));
        }
    }

    #[Test]
    public function deleted_pleadings_are_not_counted(): void
    {
        [, $owner] = $this->accountWithOwner();
        LegalCase::factory()->by($owner)->draft()->count(2)->create();
        LegalCase::factory()->by($owner)->draft()->create()->delete();

        $this->actingAs($owner)
            ->get('/painel')
            ->assertInertia(fn ($page) => $page->where('indicators.drafts', 2));
    }

    #[Test]
    public function an_account_without_pleadings_has_no_year_and_no_months(): void
    {
        [, $owner] = $this->accountWithOwner();

        $this->actingAs($owner)
            ->get('/painel')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('years', [])
                ->where('activity.year', null)
                ->where('activity.months', []));
    }
}
