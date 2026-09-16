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
 *
 * One use case, two ways back. Asked for from "Novo cliente", it lands on the
 * client that was just created. Asked for from inside another screen — the
 * client select of a pleading, which opens this same form in a dialog — the
 * `inline` flag keeps the caller where it was: the redirect goes back, and the
 * new client rides along in the flash so the select can show it chosen. Which
 * of the two is a matter of adaptation, and so lives in asController(); the
 * registration itself does not know the difference.
 */
final class CreateCustomer
{
    use AsAction;
    use ValidatesCustomer;

    /** The flashed {value,label} of an inline registration. */
    public const INLINE_FLASH_KEY = 'created_customer';

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

        // `inline` is not validated on purpose: it says nothing about the
        // client, only about where the browser should end up.
        if ($request->boolean('inline')) {
            // The fallback is the client, not the home page: without a referer
            // there is no caller to return to, and landing on what was just
            // registered beats landing nowhere in particular.
            return back(fallback: route('customers.show', $customer))->with(self::INLINE_FLASH_KEY, [
                'value' => $customer->id,
                'label' => $customer->displayName(),
            ]);
        }

        return to_route('customers.show', $customer)
            ->with('success', 'Cliente cadastrado com sucesso.');
    }
}
