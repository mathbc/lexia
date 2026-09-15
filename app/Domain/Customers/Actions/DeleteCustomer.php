<?php

declare(strict_types=1);

namespace App\Domain\Customers\Actions;

use App\Domain\Customers\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Removes a client from the registry.
 *
 * A soft delete: the row leaves every listing but the matters already attached
 * to it keep a name. It also frees the CPF/CNPJ, since the partial unique
 * indexes ignore deleted rows — the same client can be registered again.
 */
final class DeleteCustomer
{
    use AsAction;

    public function handle(Customer $customer): void
    {
        $customer->delete();
    }

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('delete', $request->route('customer'));
    }

    public function asController(Customer $customer): RedirectResponse
    {
        $this->handle($customer);

        return to_route('customers.index')
            ->with('success', "Cliente {$customer->displayName()} excluído.");
    }
}
