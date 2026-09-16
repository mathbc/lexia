<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Domain\Customers\Actions\CreateCustomer;
use App\Domain\Customers\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AssistedLegalCaseFormTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_assisted_form_lists_only_the_accounts_own_clients(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $client = Customer::factory()->forAccount($account)->create();

        [$other] = $this->accountWithOwner();
        Customer::factory()->forAccount($other)->create();

        $this->actingAs($owner)
            ->get('/pecas/nova/inteligente')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('legal-cases/assisted-form')
                ->has('customers', 1)
                ->where('customers.0.value', $client->id));
    }

    #[Test]
    public function the_assisted_form_carries_what_the_client_dialog_needs(): void
    {
        [, $owner] = $this->accountWithOwner();

        $this->actingAs($owner)
            ->get('/pecas/nova/inteligente')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('customerTypes', 2)
                ->has('states', 27)
                ->where('can.create_customer', true)
                ->where('createdCustomer', null));
    }

    /**
     * The dialog only closes once the client it just registered comes back in
     * the props, so this screen publishing the flash is what keeps it from
     * hanging open with a full form after a successful save.
     */
    #[Test]
    public function a_client_registered_from_the_dialog_comes_back_chosen(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $customer = Customer::factory()->forAccount($account)->create(['name' => 'Joana Pereira']);

        $this->actingAs($owner)
            ->withSession([CreateCustomer::INLINE_FLASH_KEY => [
                'value' => $customer->id,
                'label' => $customer->displayName(),
            ]])
            ->get('/pecas/nova/inteligente')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('createdCustomer.value', $customer->id)
                ->where('createdCustomer.label', $customer->displayName()));
    }

    /**
     * A statement of scope: this screen asks for two things, and the wizard's
     * catalogue — the steps, the areas, the 615 procedural classes — has no
     * business travelling with it.
     */
    #[Test]
    public function the_assisted_form_does_not_carry_the_wizards_catalogue(): void
    {
        [, $owner] = $this->accountWithOwner();

        $this->actingAs($owner)
            ->get('/pecas/nova/inteligente')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->missing('steps')
                ->missing('practiceAreas')
                ->missing('proceduralClasses'));
    }

    #[Test]
    public function a_guest_is_sent_to_the_login(): void
    {
        $this->get('/pecas/nova/inteligente')->assertRedirect('/login');
    }
}
