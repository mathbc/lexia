<?php

declare(strict_types=1);

namespace App\Domain\Customers\Actions;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Customers\Enums\CustomerType;
use App\Domain\Customers\Enums\MaritalStatus;
use App\Domain\Customers\Models\Customer;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The "Novo cliente" form.
 */
final class ShowCustomerForm
{
    use AsAction;

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('create', Customer::class);
    }

    public function asController(): Response
    {
        return Inertia::render('customers/create', [
            'customerTypes' => CustomerType::options(),
            'maritalStatuses' => MaritalStatus::options(),
            'states' => BrazilianState::options(),
        ]);
    }
}
