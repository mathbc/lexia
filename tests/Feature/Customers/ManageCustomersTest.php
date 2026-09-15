<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Domain\Customers\Enums\CustomerType;
use App\Domain\Customers\Models\Customer;
use App\Domain\Shared\Tenancy\TenantContext;
use App\Domain\Users\Models\User;
use Database\Factories\Support\BrazilianDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ManageCustomersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Joana Pereira',
            'type' => CustomerType::Individual->value,
            'cpf' => BrazilianDocuments::cpf(),
            'email' => 'joana@cliente.test',
            'phone' => '11988887777',
            'postal_code' => '01310-100',
            'street' => 'Avenida Paulista',
            'number' => '1000',
            'complement' => 'Sala 12',
            'district' => 'Bela Vista',
            'city' => 'São Paulo',
            'state' => 'SP',
            ...$overrides,
        ];
    }

    #[Test]
    public function a_lawyer_can_register_an_individual_client(): void
    {
        [$account] = $this->accountWithOwner();
        $lawyer = User::factory()->forAccount($account)->create();

        $cpf = BrazilianDocuments::cpf();

        $this->actingAs($lawyer)
            ->post('/clientes', $this->payload(['cpf' => $cpf]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $customer = Customer::acrossAllAccounts()->where('email', 'joana@cliente.test')->sole();

        // The account is stamped from the actor, never from the request.
        $this->assertSame($account->id, $customer->account_id);
        $this->assertSame(CustomerType::Individual, $customer->type);
        // Masks are stripped on the way in: one representation in the database.
        $this->assertSame($cpf, $customer->cpf);
        $this->assertNull($customer->cnpj);
        $this->assertSame('11988887777', $customer->phone);
        $this->assertSame('01310100', $customer->postal_code);
    }

    #[Test]
    public function a_company_client_needs_a_corporate_name_and_a_cnpj(): void
    {
        [, $owner] = $this->accountWithOwner();

        $this->actingAs($owner)
            ->post('/clientes', $this->payload([
                'type' => CustomerType::Company->value,
                'cpf' => null,
            ]))
            ->assertSessionHasErrors(['legal_name', 'cnpj']);

        $cnpj = BrazilianDocuments::cnpj();

        $this->actingAs($owner)
            ->post('/clientes', $this->payload([
                'name' => 'Padaria do Zé',
                'type' => CustomerType::Company->value,
                'legal_name' => 'Padaria do Zé Ltda.',
                'cnpj' => $cnpj,
                'cpf' => null,
            ]))
            ->assertSessionHasNoErrors();

        $customer = Customer::acrossAllAccounts()->where('cnpj', $cnpj)->sole();

        // Only the document the type calls for survives.
        $this->assertNull($customer->cpf);
        $this->assertSame('Padaria do Zé Ltda.', $customer->legal_name);
    }

    #[Test]
    public function an_invalid_document_is_refused(): void
    {
        [, $owner] = $this->accountWithOwner();

        $this->actingAs($owner)
            ->post('/clientes', $this->payload(['cpf' => '11111111111']))
            ->assertSessionHasErrors('cpf');
    }

    #[Test]
    public function a_document_is_unique_within_the_account_but_not_across_them(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        [, $stranger] = $this->accountWithOwner();

        $cpf = BrazilianDocuments::cpf();
        Customer::factory()->forAccount($account)->create(['cpf' => $cpf]);

        $this->actingAs($owner)
            ->post('/clientes', $this->payload(['cpf' => $cpf]))
            ->assertSessionHasErrors('cpf');

        // Two firms may perfectly well share a client, and neither learns
        // that the other has them.
        $this->actingAs($stranger)
            ->post('/clientes', $this->payload(['cpf' => $cpf]))
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function the_listing_only_shows_the_actors_own_clients(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        Customer::factory()->forAccount($account)->count(2)->create();

        [$other] = $this->accountWithOwner();
        Customer::factory()->forAccount($other)->count(3)->create();

        $this->actingAs($owner)->get('/clientes')
            ->assertInertia(fn ($page) => $page->where('customers.total', 2));
    }

    #[Test]
    public function platform_staff_do_not_see_another_tenants_clients(): void
    {
        // The tenant scope is deliberately open for staff, so the listing has
        // to filter by account itself and the Policy has to close the door on
        // a client resolved by route-model binding.
        [$account] = $this->accountWithOwner();
        $customer = Customer::factory()->forAccount($account)->create();
        $staff = User::factory()->platformAdmin()->create();

        $this->actingAs($staff)->get('/clientes')
            ->assertInertia(fn ($page) => $page->where('customers.total', 0));

        $this->actingAs($staff)->get("/clientes/{$customer->id}")->assertForbidden();
        $this->actingAs($staff)->delete("/clientes/{$customer->id}")->assertForbidden();
    }

    #[Test]
    public function a_client_of_another_account_cannot_be_reached(): void
    {
        [, $owner] = $this->accountWithOwner();
        [$other] = $this->accountWithOwner();
        $stranger = Customer::factory()->forAccount($other)->create();

        // Route-model binding runs before the tenant middleware, so the row is
        // resolved and it is CustomerPolicy that turns it away — 403, not 404.
        // That is the point: the scope alone would not have stopped this.
        //
        // The context is cleared between calls because TenantContext is a
        // singleton that survives from one test request to the next; a real
        // request always starts with it empty, and leaving it set would hide
        // the row at binding time and test a 404 that production never sends.
        $this->actingAs($owner)->get("/clientes/{$stranger->id}")->assertForbidden();

        app(TenantContext::class)->clear();
        $this->actingAs($owner)->put("/clientes/{$stranger->id}", $this->payload())->assertForbidden();

        app(TenantContext::class)->clear();
        $this->actingAs($owner)->delete("/clientes/{$stranger->id}")->assertForbidden();

        $this->assertNotSoftDeleted($stranger);
    }

    #[Test]
    public function a_client_can_be_edited(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $customer = Customer::factory()->forAccount($account)->create();

        $this->actingAs($owner)
            ->put("/clientes/{$customer->id}", $this->payload([
                'name' => 'Joana Pereira Lima',
                'cpf' => $customer->cpf,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect("/clientes/{$customer->id}");

        $this->assertSame('Joana Pereira Lima', $customer->fresh()->name);
    }

    #[Test]
    public function only_who_manages_the_account_may_delete_a_client(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $lawyer = User::factory()->forAccount($account)->create();
        $customer = Customer::factory()->forAccount($account)->create();

        $this->actingAs($lawyer)->delete("/clientes/{$customer->id}")->assertForbidden();
        $this->assertNotSoftDeleted($customer);

        $this->actingAs($owner)->delete("/clientes/{$customer->id}")
            ->assertRedirect('/clientes');

        $this->assertSoftDeleted($customer);
    }

    #[Test]
    public function a_deleted_client_frees_their_document_again(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $cpf = BrazilianDocuments::cpf();
        $customer = Customer::factory()->forAccount($account)->create(['cpf' => $cpf]);

        $this->actingAs($owner)->delete("/clientes/{$customer->id}");

        $this->actingAs($owner)
            ->post('/clientes', $this->payload(['cpf' => $cpf]))
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function the_listing_can_be_searched_and_filtered(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $cpf = BrazilianDocuments::cpf();

        Customer::factory()->forAccount($account)->create(['name' => 'Joana Pereira', 'cpf' => $cpf]);
        Customer::factory()->forAccount($account)->company()->create(['name' => 'Padaria do Zé']);

        $this->actingAs($owner)->get('/clientes?search=Joana')
            ->assertInertia(fn ($page) => $page->where('customers.total', 1));

        $this->actingAs($owner)->get('/clientes?type=company')
            ->assertInertia(fn ($page) => $page
                ->where('customers.total', 1)
                ->where('customers.data.0.name', 'Padaria do Zé'));

        // A document typed with its mask still finds the row, which is stored
        // as bare digits.
        $masked = substr($cpf, 0, 3).'.'.substr($cpf, 3, 3);

        $this->actingAs($owner)->get('/clientes?search='.urlencode($masked))
            ->assertInertia(fn ($page) => $page->where('customers.total', 1));
    }
}
