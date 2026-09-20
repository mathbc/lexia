<?php

declare(strict_types=1);

namespace App\Domain\Customers\Actions;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Customers\Enums\CustomerType;
use App\Domain\Customers\Enums\MaritalStatus;
use App\Domain\Customers\Models\Customer;
use App\Domain\Customers\Support\CustomerPageProps;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * One client's registration details.
 *
 * The screen is the form: whoever may edit types straight into it, and whoever
 * may not reads the same fields disabled.
 */
final class ShowCustomer
{
    use AsAction;

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('view', $request->route('customer'));
    }

    public function asController(Customer $customer, ActionRequest $request): Response
    {
        return Inertia::render('customers/show', [
            'customer' => $customer,
            'can' => CustomerPageProps::abilities($request->user(), $customer),
            'customerTypes' => CustomerType::options(),
            'maritalStatuses' => MaritalStatus::options(),
            'states' => BrazilianState::options(),
        ]);
    }
}
