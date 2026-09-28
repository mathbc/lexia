<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Actions\Concerns\ValidatesAccount;
use App\Domain\Accounts\Data\AccountData;
use App\Domain\Accounts\Data\AccountLogosData;
use App\Domain\Accounts\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Edits the account's own registration details, the logos included.
 *
 * One transaction for both, so a logo that fails to store does not leave the
 * rest of the form half saved. The route is PUT, but a form carrying a file
 * arrives as POST with `_method=put`: PHP only parses a multipart body on POST.
 */
final class UpdateAccount
{
    use AsAction;
    use ValidatesAccount;

    public function handle(Account $account, AccountData $data, AccountLogosData $logos): Account
    {
        DB::transaction(function () use ($account, $data, $logos): void {
            $account->update($data->toArray());

            SaveAccountLogos::make()->handle($account, $logos);
        });

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
        return [
            ...$this->accountRules(ignoring: $request->route('account')),
            ...$this->logoRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return [
            ...$this->accountAttributes(),
            ...$this->logoAttributes(),
        ];
    }

    public function asController(Account $account, ActionRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $this->handle($account, AccountData::fromArray($validated), AccountLogosData::fromArray($validated));

        return to_route('accounts.show', $account)
            ->with('success', 'Dados da conta atualizados com sucesso.');
    }
}
