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
 * Registers a client for an account.
 */
final class CreateCustomer
{
    use AsAction;
    use ValidatesCustomer;

    public function handle(string $accountId, CustomerData $data): Customer
    {
        $customer = new Customer($data->toArray());

        // Explicit rather than left to BelongsToAccount: the account is an
        // argument of this use case, so it is set where it can be seen.
        $customer->account_id = $accountId;
        $customer->save();

        return $customer;
    }

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('create', Customer::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(ActionRequest $request): array
    {
        return $this->customerRules($request->user()->account_id);
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return $this->customerAttributes();
    }

    public function asController(ActionRequest $request): RedirectResponse
    {
        $customer = $this->handle(
            $request->user()->account_id,
            CustomerData::fromArray($request->validated()),
        );

        return to_route('customers.show', $customer)
            ->with('success', 'Cliente cadastrado com sucesso.');
    }
}
