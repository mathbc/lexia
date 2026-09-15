<?php

declare(strict_types=1);

namespace App\Domain\Customers\Actions;

use App\Domain\Customers\Actions\Concerns\ValidatesCustomer;
use App\Domain\Customers\Data\CustomerData;
use App\Domain\Customers\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Edits a client's registration details.
 */
final class UpdateCustomer
{
    use AsAction;
    use ValidatesCustomer;

    public function handle(Customer $customer, CustomerData $data): Customer
    {
        $customer->update($data->toArray());

        return $customer->refresh();
    }

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('update', $request->route('customer'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(ActionRequest $request): array
    {
        $customer = $request->route('customer');

        // The client's own account, not the actor's: uniqueness is checked
        // among the rows this record actually lives with.
        return $this->customerRules($customer->account_id, ignoring: $customer);
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return $this->customerAttributes();
    }

    public function asController(Customer $customer, ActionRequest $request): RedirectResponse
    {
        $this->handle($customer, CustomerData::fromArray($request->validated()));

        return to_route('customers.show', $customer)
            ->with('success', 'Cliente atualizado com sucesso.');
    }
}
