<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Domain\Customers\Actions\CreateCustomer;
use App\Domain\Customers\Models\Customer;
use App\Domain\LegalCases\Enums\LegalCaseStep;
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
                ->component('legal-cases/form')
                ->has('practiceAreas', 24)
                ->has('proceduralClasses', 0)
                ->where('selectedArea', ''));
    }

    #[Test]
    public function the_form_carries_what_the_client_dialog_needs(): void
    {
        [, $owner] = $this->accountWithOwner();

        $this->actingAs($owner)
            ->get('/pecas/nova')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('customerTypes', 2)
                ->has('states', 27)
                ->where('can.create_customer', true)
                // Nothing was just registered, so the select has nobody to
                // pre-select.
                ->where('createdCustomer', null));
    }

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
            ->get('/pecas/nova')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('createdCustomer.value', $customer->id)
                ->where('createdCustomer.label', $customer->displayName()));
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
                // Trabalhista has a rite of its own — 43 classes the CNJ puts
                // under its own subtree — and on top of that the civil trunk
                // classes the CNJ marks as valid in just_trab_*, which is how
                // Embargos à Execução reaches a labour matter.
                ->has('proceduralClasses', 86)
                ->where('proceduralClasses.0.scope', 'specific'));
    }

    #[Test]
    public function each_class_reaches_the_picker_with_what_it_takes_to_choose_one(): void
    {
        [, $owner] = $this->accountWithOwner();

        $this->actingAs($owner)
            ->get('/pecas/nova?area=imobiliario')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // Nome e código não bastam para separar Despejo de Despejo por
                // Falta de Pagamento: o card mostra a descrição e as matérias,
                // e a busca do seletor alcança estas últimas.
                //
                // O resto da ficha viaja junto porque o diálogo do olho a
                // mostra inteira sem uma segunda ida ao servidor.
                ->has('proceduralClasses.0', fn ($class) => $class
                    ->hasAll([
                        'description', 'typical_subjects', 'nature', 'legal_basis',
                        'active_party', 'passive_party', 'has_own_numbering',
                        'is_cross_cutting', 'path',
                    ])
                    ->etc()));
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

    #[Test]
    public function a_card_carries_the_step_it_stopped_on_and_whether_it_is_a_draft(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        LegalCase::factory()->forAccount($account)->draft(LegalCaseStep::Facts)->create();

        $this->actingAs($owner)
            ->get('/pecas')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('legalCases.data.0.current_step', 'facts')
                // O rótulo vem resolvido do enum: o React não repete o
                // português.
                ->where('legalCases.data.0.current_step_label', 'Fatos e tutela')
                ->where('legalCases.data.0.is_draft', true));
    }

    #[Test]
    public function the_listing_filters_by_status(): void
    {
        [$account, $owner] = $this->accountWithOwner();

        LegalCase::factory()->forAccount($account)->draft()->count(2)->create();
        LegalCase::factory()->forAccount($account)->finalised()->create();

        $this->actingAs($owner)
            ->get('/pecas?status=draft')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('legalCases.total', 2));

        $this->actingAs($owner)
            ->get('/pecas?status=final')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('legalCases.total', 1));
    }

    #[Test]
    public function an_unknown_status_filters_nothing_rather_than_the_opposite(): void
    {
        [$account, $owner] = $this->accountWithOwner();

        LegalCase::factory()->forAccount($account)->draft()->create();
        LegalCase::factory()->forAccount($account)->finalised()->create();

        $this->actingAs($owner)
            ->get('/pecas?status=talvez')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('legalCases.total', 2));
    }

    #[Test]
    public function the_edit_url_hydrates_the_form_with_what_was_saved(): void
    {
        [$account, $owner] = $this->accountWithOwner();

        $legalCase = LegalCase::factory()->forAccount($account)->create([
            'court_addressing' => 'Ao Juízo da 1ª Vara Cível',
            'defendant_name' => 'Construtora Atlântico Ltda.',
            'facts' => 'O imóvel foi ocupado em março.',
        ]);

        $this->actingAs($owner)
            ->get("/pecas/{$legalCase->id}/editar")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('legal-cases/form')
                ->where('legalCase.court_addressing', 'Ao Juízo da 1ª Vara Cível')
                ->where('legalCase.defendant.defendant_name', 'Construtora Atlântico Ltda.')
                ->where('legalCase.facts.facts', 'O imóvel foi ocupado em março.')
                // O slug, e não o uuid: é a moeda do seletor de área.
                ->where('legalCase.practice_area', $legalCase->practiceArea->slug));
    }

    #[Test]
    public function the_edit_url_opens_on_the_step_the_query_string_names(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $legalCase = LegalCase::factory()->forAccount($account)->draft(LegalCaseStep::Review)->create();

        $this->actingAs($owner)
            ->get("/pecas/{$legalCase->id}/editar?etapa=defendant")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('initialStep', 'defendant')
                // A marca d'água não se mexe por causa de onde a tela abriu.
                ->where('legalCase.current_step', 'review'));
    }

    #[Test]
    public function the_edit_url_falls_back_to_the_furthest_step_reached(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $legalCase = LegalCase::factory()->forAccount($account)->draft(LegalCaseStep::Requirements)->create();

        $this->actingAs($owner)
            ->get("/pecas/{$legalCase->id}/editar")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('initialStep', 'requirements'));
    }

    #[Test]
    public function the_edit_url_loads_the_classes_of_the_area_already_chosen(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $labour = PracticeArea::query()->where('slug', 'trabalhista')->sole();
        $legalCase = LegalCase::factory()->forAccount($account)->inArea($labour)->create();

        $this->actingAs($owner)
            ->get("/pecas/{$legalCase->id}/editar")
            ->assertOk()
            // Sem este fallback o rascunho reabriria com a lista de classes
            // vazia sob uma classe visivelmente escolhida.
            ->assertInertia(fn ($page) => $page
                ->where('selectedArea', 'trabalhista')
                ->has('proceduralClasses', 86));
    }

    #[Test]
    public function a_stranger_cannot_open_another_accounts_pleading(): void
    {
        [$account] = $this->accountWithOwner();
        $legalCase = LegalCase::factory()->forAccount($account)->create();

        [, $stranger] = $this->accountWithOwner();

        $this->actingAs($stranger)
            ->get("/pecas/{$legalCase->id}/editar")
            ->assertForbidden();
    }
}
