<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Models\Account;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Flips the customer-controlled `active` switch.
 *
 * The `enabled` flag is LexIA's and is deliberately not reachable here.
 */
final class ToggleAccountStatus
{
    use AsAction;

    public function handle(Account $account): Account
    {
        $account->update(['active' => ! $account->active]);

        return $account;
    }

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('toggleStatus', $request->route('account'));
    }

    public function asController(Account $account): RedirectResponse
    {
        $this->handle($account);

        return back()->with(
            'success',
            $account->active ? 'Conta reativada.' : 'Conta desativada.',
        );
    }
}
