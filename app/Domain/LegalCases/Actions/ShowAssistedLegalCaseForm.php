<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Customers\Actions\CreateCustomer;
use App\Domain\Customers\Enums\CustomerType;
use App\Domain\Customers\Models\Customer;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalCases\Support\LegalCaseOptions;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The other door into a new pleading: the lawyer names the client and tells
 * the story, and the agents work out the framing.
 *
 * It sits one adjective away from ShowLegalCaseForm on purpose — the two are
 * the same decision taken twice, and the choice between them is the dialog on
 * the listing. What separates them is how much the lawyer fills in: there the
 * practice area, the procedural class and the addressing are chosen by hand;
 * here they are deduced from the facts, and the lawyer lands on that same
 * six-step form with the answers already in place.
 *
 * So this screen deliberately carries none of the wizard's catalogue — no
 * steps, no areas, none of the 615 procedural classes. It asks for two things
 * and publishes exactly what those two need, which is the client list plus
 * what the inline client dialog opens with: the registration it offers is the
 * same CreateCustomer, and `createdCustomer` is how the client just saved
 * finds its way back here chosen.
 */
final class ShowAssistedLegalCaseForm
{
    use AsAction;

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('create', LegalCase::class);
    }

    public function asController(ActionRequest $request): Response
    {
        return Inertia::render('legal-cases/assisted-form', [
            'customers' => LegalCaseOptions::customers($request->user()->account_id),
            'customerTypes' => CustomerType::options(),
            'states' => BrazilianState::options(),
            'can' => [
                'create_customer' => $request->user()->can('create', Customer::class),
            ],
            'createdCustomer' => $request->session()->get(CreateCustomer::INLINE_FLASH_KEY),
        ]);
    }
}
