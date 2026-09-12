<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Models\Account;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RegisterAccountTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'type' => AccountType::LawFirm->value,
            'name' => 'Alfa Advogados',
            'legal_name' => 'Alfa Sociedade de Advogados Ltda.',
            'federal_id' => '11222333000181',
            'email' => 'contato@alfa.adv.br',
            'phone' => '11999998888',
            'postal_code' => '01310100',
            'street' => 'Avenida Paulista',
            'number' => '1000',
            'complement' => 'Conj. 51',
            'district' => 'Bela Vista',
            'city' => 'São Paulo',
            'state' => 'SP',
            'owner_name' => 'Renata Alfa',
            'owner_email' => 'renata@alfa.adv.br',
            'owner_type' => 'lawyer',
            'owner_birth_date' => '1985-04-12',
            'password' => 'Senha-Muito-Forte-1',
            'password_confirmation' => 'Senha-Muito-Forte-1',
            ...$overrides,
        ];
    }

    #[Test]
    public function signing_up_creates_the_account_and_its_first_admin(): void
    {
        $this->post('/cadastro', $this->payload())->assertRedirect('/painel');

        $account = Account::sole();
        $this->assertSame('Alfa Sociedade de Advogados Ltda.', $account->legal_name);
        $this->assertSame('11222333000181', $account->federal_id);
        $this->assertTrue($account->isOperational());

        $owner = User::acrossAllAccounts()->sole();
        $this->assertSame(UserRole::AccountAdmin, $owner->role);
        $this->assertSame($account->id, $owner->account_id);
        $this->assertAuthenticatedAs($owner);
    }

    #[Test]
    public function an_invalid_cnpj_is_rejected(): void
    {
        $this->post('/cadastro', $this->payload(['federal_id' => '11222333000199']))
            ->assertSessionHasErrors('federal_id');

        $this->assertSame(0, Account::count());
    }

    #[Test]
    public function a_firm_must_supply_a_corporate_name(): void
    {
        $this->post('/cadastro', $this->payload(['legal_name' => '']))
            ->assertSessionHasErrors('legal_name');
    }

    #[Test]
    public function an_individual_must_supply_an_oab_enrolment(): void
    {
        $this->post('/cadastro', $this->payload([
            'type' => AccountType::Individual->value,
            'federal_id' => '',
            'legal_name' => '',
        ]))->assertSessionHasErrors(['oab_number', 'oab_state']);
    }

    #[Test]
    public function the_same_cnpj_cannot_register_twice(): void
    {
        // Seeded directly rather than through a first sign-up, which would
        // leave the test authenticated and bounce off the `guest` middleware.
        Account::factory()->lawFirm()->create(['federal_id' => '11222333000181']);

        $this->post('/cadastro', $this->payload())->assertSessionHasErrors('federal_id');

        $this->assertSame(1, Account::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_cnpj_freed_by_a_soft_deleted_account_can_be_reused(): void
    {
        Account::factory()->lawFirm()->create(['federal_id' => '11222333000181'])->delete();

        $this->post('/cadastro', $this->payload())->assertRedirect('/painel');

        // withoutGlobalScopes() also drops the SoftDeletes scope, so the
        // trashed row is counted too: one live account, two rows in total.
        $this->assertSame(2, Account::withoutGlobalScopes()->count());
        $this->assertSame(1, Account::withoutGlobalScopes()->whereNull('deleted_at')->count());
    }

    #[Test]
    public function nothing_is_persisted_when_the_user_fails_validation(): void
    {
        // The account and the owner are created in one transaction, so a
        // duplicate owner email must not leave an orphaned account behind.
        User::factory()->create(['email' => 'renata@alfa.adv.br']);

        $this->post('/cadastro', $this->payload())->assertSessionHasErrors('owner_email');

        $this->assertSame(1, Account::withoutGlobalScopes()->count());
    }
}
