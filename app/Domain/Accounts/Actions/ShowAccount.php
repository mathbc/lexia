<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Support\AccountPageProps;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The "Dados gerais" tab of an account.
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
        return Inertia::render('accounts/show', [
            ...AccountPageProps::for($account, $request->user()),
            // Mirrors the validation rule: the platform type is only ever an
            // option for the account that already has it.
            'accountTypes' => $account->type === AccountType::Platform
                ? AccountType::options()
                : AccountType::customerOptions(),
            'states' => BrazilianState::options(),
        ]);
    }
}
