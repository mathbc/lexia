<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Accounts\Models\Account;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The "Minha Conta" screen.
 */
final class ShowAccount
{
    use AsAction;

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('view', $request->route('account'));
    }

    public function asController(Account $account, ActionRequest $request): Response
    {
        return Inertia::render('accounts/edit', [
            'account' => $account,
            'canUpdate' => $request->user()->can('update', $account),
            'accountTypes' => AccountType::options(),
            'states' => BrazilianState::options(),
        ]);
    }
}
