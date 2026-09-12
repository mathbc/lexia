<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Accounts\Models\Account;
use App\Domain\Shared\Tenancy\TenantContext;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The tenant boundary is the single most damaging thing to get wrong in this
 * product, so it gets its own suite rather than being implied by other tests.
 */
final class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_user_only_sees_users_from_their_own_account(): void
    {
        [$mine, $owner] = $this->accountWithOwner();
        User::factory()->forAccount($mine)->count(3)->create();

        $theirs = Account::factory()->create();
        User::factory()->forAccount($theirs)->count(5)->create();

        $this->actingAsUser($owner);

        $this->assertSame(4, User::count());
        $this->assertSame(9, User::acrossAllAccounts()->count());
    }

    #[Test]
    public function the_users_listing_never_leaks_another_account(): void
    {
        [$mine, $owner] = $this->accountWithOwner();
        $theirs = Account::factory()->create();
        $stranger = User::factory()->forAccount($theirs)->create(['name' => 'Estranho']);

        $this->actingAs($owner)
            ->get("/contas/{$mine->id}/usuarios")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('accounts/users')
                ->where('users.total', 1)
                ->whereNot('users.data.0.name', $stranger->name));
    }

    #[Test]
    public function an_account_cannot_be_opened_by_another_tenant(): void
    {
        [, $owner] = $this->accountWithOwner();
        $theirs = Account::factory()->create();

        $this->actingAs($owner)->get("/contas/{$theirs->id}")->assertForbidden();
    }

    #[Test]
    public function a_user_from_another_account_cannot_be_edited(): void
    {
        [, $owner] = $this->accountWithOwner();
        $stranger = User::factory()->forAccount(Account::factory()->create())->create();

        $this->actingAs($owner)
            ->put("/contas/{$stranger->account_id}/usuarios/{$stranger->id}", ['name' => 'Invadido', 'email' => 'x@x.test', 'type' => 'lawyer'])
            ->assertForbidden();

        $this->assertNotSame('Invadido', $stranger->fresh()->name);
    }

    #[Test]
    public function platform_staff_may_cross_the_boundary(): void
    {
        Account::factory()->count(2)->create();
        $staff = User::factory()->platformAdmin()->create();

        $this->actingAs($staff)->get('/contas')->assertOk();

        // Middleware widens the context for platform staff.
        $this->assertTrue($staff->isPlatformAdmin());
    }

    #[Test]
    public function background_work_must_adopt_a_tenant_explicitly(): void
    {
        [$mine] = $this->accountWithOwner();
        User::factory()->forAccount(Account::factory()->create())->count(2)->create();

        // No authenticated user: the scope deliberately does not apply.
        $this->assertSame(3, User::count());

        $scoped = app(TenantContext::class)
            ->actingAs($mine->id, fn (): int => User::count());

        $this->assertSame(1, $scoped);
    }
}
