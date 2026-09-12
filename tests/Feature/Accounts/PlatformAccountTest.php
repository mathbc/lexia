<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Models\Account;
use App\Domain\Users\Actions\ToggleUserStatus;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * LexIA's own account and the role that administers every other one.
 */
final class PlatformAccountTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_migration_creates_the_platform_account(): void
    {
        $platform = Account::platform();

        $this->assertSame(Account::PLATFORM_ID, $platform->id);
        $this->assertSame('LexIA', $platform->name);
        $this->assertSame(AccountType::Platform, $platform->type);
        $this->assertTrue($platform->isPlatform());
        $this->assertTrue($platform->isOperational());
    }

    #[Test]
    public function there_is_exactly_one_platform_account(): void
    {
        Account::factory()->count(3)->create();

        $this->assertSame(1, Account::query()->where('type', AccountType::Platform)->count());
    }

    #[Test]
    public function platform_admins_live_in_the_platform_account(): void
    {
        $staff = User::factory()->platformAdmin()->create();

        $this->assertSame(Account::PLATFORM_ID, $staff->account_id);
        $this->assertSame(UserRole::PlatformAdmin, $staff->role);
        $this->assertTrue($staff->isPlatformAdmin());
    }

    #[Test]
    public function a_platform_admin_sees_the_users_of_every_account(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $other = Account::factory()->create();
        User::factory()->forAccount($other)->count(2)->create();
        $staff = User::factory()->platformAdmin()->create();

        // Staff open one tenant at a time, and see all of them in the registry.
        $this->actingAs($staff)
            ->get("/contas/{$account->id}/usuarios")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('users.total', 1));

        $this->actingAs($staff)
            ->get("/contas/{$other->id}/usuarios")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('users.total', 2));

        // The two customers plus LexIA's own account.
        $this->actingAs($staff)
            ->get('/contas')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('accounts.total', 3));

        $this->assertTrue($owner->exists);
    }

    #[Test]
    public function a_platform_admin_can_edit_a_user_from_another_account(): void
    {
        [$account] = $this->accountWithOwner();
        $lawyer = User::factory()->forAccount($account)->create();
        $staff = User::factory()->platformAdmin()->create();

        $this->actingAs($staff)->put("/contas/{$account->id}/usuarios/{$lawyer->id}", [
            'name' => 'Nome Corrigido',
            'email' => $lawyer->email,
            'type' => 'lawyer',
            'role' => UserRole::Admin->value,
        ])->assertRedirect("/contas/{$account->id}/usuarios");

        $lawyer->refresh();
        $this->assertSame('Nome Corrigido', $lawyer->name);
        $this->assertSame(UserRole::Admin, $lawyer->role);
    }

    #[Test]
    public function a_platform_admin_can_edit_and_deactivate_another_account(): void
    {
        [$account] = $this->accountWithOwner();
        $staff = User::factory()->platformAdmin()->create();

        $this->actingAs($staff)->get("/contas/{$account->id}")->assertOk();

        $this->actingAs($staff)
            ->put("/contas/{$account->id}", $this->accountPayload($account))
            ->assertRedirect();

        $this->actingAs($staff)
            ->patch("/contas/{$account->id}/status")
            ->assertRedirect();

        $account->refresh();
        $this->assertSame('Nome Ajustado', $account->name);
        $this->assertFalse($account->active);
    }

    #[Test]
    public function the_platform_account_cannot_be_deactivated(): void
    {
        $staff = User::factory()->platformAdmin()->create();

        $this->actingAs($staff)
            ->patch('/contas/'.Account::PLATFORM_ID.'/status')
            ->assertForbidden();

        $this->assertTrue(Account::platform()->active);
    }

    #[Test]
    public function the_last_platform_admin_cannot_be_disabled(): void
    {
        // Staff manage each other, so the guard is the only thing standing
        // between LexIA and an unadministrable platform.
        $staff = User::factory()->platformAdmin()->create();

        try {
            ToggleUserStatus::run($staff);
            $this->fail('The last platform admin was disabled.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('enabled', $exception->errors());
        }

        $this->assertTrue($staff->fresh()->enabled);
    }

    #[Test]
    public function sign_up_cannot_create_a_platform_account(): void
    {
        $this->post('/cadastro', [
            'type' => AccountType::Platform->value,
            'name' => 'Falsa Plataforma',
            'email' => 'falsa@plataforma.test',
            'phone' => '11999998888',
            'postal_code' => '01310100',
            'street' => 'Avenida Paulista',
            'number' => '1000',
            'district' => 'Bela Vista',
            'city' => 'São Paulo',
            'state' => 'SP',
            'owner_name' => 'Falso Dono',
            'owner_email' => 'falso@plataforma.test',
            'owner_type' => 'lawyer',
            'password' => 'senha-bem-comprida-1',
            'password_confirmation' => 'senha-bem-comprida-1',
        ])->assertSessionHasErrors('type');

        $this->assertSame(1, Account::query()->where('type', AccountType::Platform)->count());
    }

    #[Test]
    public function an_account_admin_cannot_assign_the_platform_role(): void
    {
        [, $owner] = $this->accountWithOwner();

        $this->actingAs($owner)->post("/contas/{$owner->account_id}/usuarios", [
            'name' => 'Falso Admin',
            'email' => 'falso@lexia.test',
            'type' => 'lawyer',
            'role' => UserRole::PlatformAdmin->value,
        ])->assertSessionHasErrors('role');

        $this->assertSame(0, User::acrossAllAccounts()->where('email', 'falso@lexia.test')->count());
    }

    /**
     * A complete, valid edit of an individual account.
     *
     * @return array<string, string>
     */
    private function accountPayload(Account $account): array
    {
        return [
            'name' => 'Nome Ajustado',
            'type' => AccountType::Individual->value,
            'oab_number' => (string) $account->oab_number,
            'oab_state' => (string) $account->oab_state?->value,
            'email' => 'contato@ajustado.adv.br',
            'phone' => '11999998888',
            'postal_code' => '01310100',
            'street' => 'Avenida Paulista',
            'number' => '1000',
            'district' => 'Bela Vista',
            'city' => 'São Paulo',
            'state' => 'SP',
        ];
    }
}
