<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Actions\Concerns\ValidatesAccount;
use App\Domain\Accounts\Data\AccountData;
use App\Domain\Accounts\Models\Account;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Edits the account's own registration details.
 */
final class UpdateAccount
{
    use AsAction;
    use ValidatesAccount;

    public function handle(Account $account, AccountData $data): Account
    {
        $account->update($data->toArray());

        return $account->refresh();
    }

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('update', $request->route('account'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(ActionRequest $request): array
    {
        return $this->accountRules(ignoring: $request->route('account'));
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return $this->accountAttributes();
    }

    public function asController(Account $account, ActionRequest $request): RedirectResponse
    {
        $this->handle($account, AccountData::fromArray($request->validated()));

        return to_route('accounts.edit', $account)
            ->with('success', 'Dados da conta atualizados com sucesso.');
    }
}
