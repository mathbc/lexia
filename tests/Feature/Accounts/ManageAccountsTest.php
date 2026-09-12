<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Scopes\VisibleAccountScope;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The accounts registry: the screen only LexIA staff reach.
 */
final class ManageAccountsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function staff_can_open_an_account_and_create_one(): void
    {
        Notification::fake();
        $staff = User::factory()->platformAdmin()->create();

        $this->actingAs($staff)->get('/contas/nova')->assertOk();

        $this->actingAs($staff)
            ->post('/contas', $this->payload())
            ->assertRedirect();

        $account = Account::query()->where('email', 'contato@novabanca.adv.br')->sole();
        $owner = User::acrossAllAccounts()->where('email', 'dono@novabanca.adv.br')->sole();

        $this->assertSame(AccountType::LawFirm, $account->type);
        $this->assertSame($account->id, $owner->account_id);
        // Somebody has to be able to administer the account that was created.
        $this->assertSame(UserRole::AccountAdmin, $owner->role);
        $this->assertTrue($owner->enabled);
    }

    #[Test]
    public function both_tabs_and_the_user_form_render_under_the_account(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $lawyer = User::factory()->forAccount($account)->create();

        $this->actingAs($owner)
            ->get("/contas/{$account->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('accounts/show')
                ->where('can.manage_users', true));

        $this->actingAs($owner)
            ->get("/contas/{$account->id}/usuarios/{$lawyer->id}/editar")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('users/edit')
                ->where('account.id', $account->id));

        $this->actingAs($owner)
            ->get("/contas/{$account->id}/usuarios/novo")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('users/create'));
    }

    #[Test]
    public function a_lawyer_sees_their_account_without_the_users_tab(): void
    {
        [$account] = $this->accountWithOwner();
        $lawyer = User::factory()->forAccount($account)->create();

        $this->actingAs($lawyer)
            ->get("/contas/{$account->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.manage_users', false)
                ->where('can.update', false));
    }

    #[Test]
    public function the_registry_is_closed_to_customers(): void
    {
        [, $owner] = $this->accountWithOwner();

        $this->actingAs($owner)->get('/contas')->assertForbidden();
        $this->actingAs($owner)->get('/contas/nova')->assertForbidden();
        $this->actingAs($owner)->post('/contas', $this->payload())->assertForbidden();

        $this->assertSame(0, Account::withoutGlobalScope(VisibleAccountScope::class)
            ->where('email', 'contato@novabanca.adv.br')
            ->count());
    }

    #[Test]
    public function the_registry_can_be_searched_and_filtered(): void
    {
        Account::factory()->lawFirm()->create(['name' => 'Banca Alfa']);
        Account::factory()->inactive()->create(['name' => 'Cliente Inativo']);
        $staff = User::factory()->platformAdmin()->create();

        $this->actingAs($staff)->get('/contas?search=Alfa')
            ->assertInertia(fn ($page) => $page->where('accounts.total', 1));

        // Regression guard: `active=0` must mean inactive, not active.
        $this->actingAs($staff)->get('/contas?active=0')
            ->assertInertia(fn ($page) => $page
                ->where('accounts.total', 1)
                ->where('accounts.data.0.name', 'Cliente Inativo'));
    }

    #[Test]
    public function a_user_cannot_be_managed_through_another_accounts_url(): void
    {
        // Both models are bound before the tenant middleware runs, so the
        // mismatch has to be refused by the Action itself.
        [$account] = $this->accountWithOwner();
        $stranger = User::factory()->forAccount(Account::factory()->create())->create();
        $staff = User::factory()->platformAdmin()->create();

        $this->actingAs($staff)
            ->get("/contas/{$account->id}/usuarios/{$stranger->id}/editar")
            ->assertForbidden();

        $this->actingAs($staff)
            ->patch("/contas/{$account->id}/usuarios/{$stranger->id}/status")
            ->assertForbidden();

        $this->assertTrue($stranger->fresh()->enabled);
    }

    /**
     * @return array<string, string>
     */
    private function payload(): array
    {
        return [
            'name' => 'Nova Banca',
            'legal_name' => 'Nova Banca Sociedade de Advogados Ltda.',
            'type' => AccountType::LawFirm->value,
            'federal_id' => '11222333000181',
            'email' => 'contato@novabanca.adv.br',
            'phone' => '11999998888',
            'postal_code' => '01310100',
            'street' => 'Avenida Paulista',
            'number' => '1000',
            'district' => 'Bela Vista',
            'city' => 'São Paulo',
            'state' => 'SP',
            'owner_name' => 'Dona da Banca',
            'owner_email' => 'dono@novabanca.adv.br',
            'owner_type' => 'lawyer',
        ];
    }
}
