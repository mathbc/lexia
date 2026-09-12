<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ManageUsersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_admin_can_invite_a_lawyer(): void
    {
        Notification::fake();
        [, $owner] = $this->accountWithOwner();

        $this->actingAs($owner)->post('/usuarios', [
            'name' => 'Novo Advogado',
            'email' => 'novo@lexia.test',
            'type' => 'lawyer',
            'role' => UserRole::Lawyer->value,
            'oab_number' => '123456',
            'oab_state' => 'SP',
            'birth_date' => '1990-01-01',
        ])->assertRedirect('/usuarios');

        $invited = User::acrossAllAccounts()->where('email', 'novo@lexia.test')->sole();
        $this->assertSame($owner->account_id, $invited->account_id);
        $this->assertSame(UserRole::Lawyer, $invited->role);
        $this->assertTrue($invited->enabled);
    }

    #[Test]
    public function an_admin_cannot_mint_a_peer_admin(): void
    {
        [$account] = $this->accountWithOwner();
        $admin = User::factory()->forAccount($account)->admin()->create();

        $this->actingAs($admin)->post('/usuarios', [
            'name' => 'Outro Admin',
            'email' => 'outro@lexia.test',
            'type' => 'lawyer',
            'role' => UserRole::Admin->value,
        ])->assertSessionHasErrors('role');

        $this->assertSame(0, User::acrossAllAccounts()->where('email', 'outro@lexia.test')->count());
    }

    #[Test]
    public function a_lawyer_cannot_reach_the_listing(): void
    {
        [$account] = $this->accountWithOwner();
        $lawyer = User::factory()->forAccount($account)->create();

        $this->actingAs($lawyer)->get('/usuarios')->assertForbidden();
        $this->actingAs($lawyer)->get('/usuarios/novo')->assertForbidden();
    }

    #[Test]
    public function a_lawyer_cannot_promote_themselves(): void
    {
        [$account] = $this->accountWithOwner();
        $lawyer = User::factory()->forAccount($account)->create();

        $this->actingAs($lawyer)->put("/usuarios/{$lawyer->id}", [
            'name' => 'Eu Mesmo',
            'email' => $lawyer->email,
            'type' => 'lawyer',
            'role' => UserRole::AccountAdmin->value,
        ])->assertRedirect();

        // The name change is allowed; the smuggled role is ignored.
        $lawyer->refresh();
        $this->assertSame('Eu Mesmo', $lawyer->name);
        $this->assertSame(UserRole::Lawyer, $lawyer->role);
    }

    #[Test]
    public function account_admins_cannot_disable_each_other(): void
    {
        // Peers never manage peers, which is what stops two owners from
        // locking one another out of the account.
        [$account, $owner] = $this->accountWithOwner();
        $second = User::factory()->forAccount($account)->accountAdmin()->create();

        $this->actingAs($owner)->patch("/usuarios/{$second->id}/status")->assertForbidden();

        $this->assertTrue($second->fresh()->enabled);
    }

    #[Test]
    public function the_last_account_admin_cannot_be_disabled(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $second = User::factory()->forAccount($account)->accountAdmin()->create();
        $staff = User::factory()->platformAdmin()->create();

        // Platform staff outrank everyone, so the second admin goes down.
        $this->actingAs($staff)->patch("/usuarios/{$second->id}/status")->assertRedirect();
        $this->assertFalse($second->fresh()->enabled);

        // The last enabled admin is protected even from platform staff:
        // an account with none can never be administered again.
        $this->actingAs($staff)
            ->patch("/usuarios/{$owner->id}/status")
            ->assertSessionHasErrors('enabled');

        $this->assertTrue($owner->fresh()->enabled);
    }

    #[Test]
    public function the_listing_can_be_searched_and_filtered(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        User::factory()->forAccount($account)->create(['name' => 'Joana Pereira']);
        User::factory()->forAccount($account)->disabled()->create(['name' => 'Inativo Silva']);

        $this->actingAs($owner)->get('/usuarios?search=Joana')
            ->assertInertia(fn ($page) => $page->where('users.total', 1));

        // Regression guard: `enabled=0` must mean disabled, not enabled.
        $this->actingAs($owner)->get('/usuarios?enabled=0')
            ->assertInertia(fn ($page) => $page
                ->where('users.total', 1)
                ->where('users.data.0.name', 'Inativo Silva'));
    }
}
