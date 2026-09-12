<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions\Concerns;

use App\Domain\Accounts\Models\Account;
use App\Domain\Users\Models\User;
use Lorisleiva\Actions\ActionRequest;

/**
 * Guards the account segment of the nested user routes.
 *
 * Users now live under /contas/{account}/usuarios, which means the URL carries
 * two independently bound models. Route-model binding resolves both before the
 * tenant middleware runs, so a stranger's account — or a user who belongs to a
 * different one than the URL claims — reaches the Action and must be refused
 * here. This is the check; the ability on the user itself comes on top of it.
 */
trait ActsWithinAccount
{
    protected function withinRoutedAccount(ActionRequest $request): bool
    {
        $account = $request->route('account');

        if (! $account instanceof Account || ! $request->user()->can('view', $account)) {
            return false;
        }

        $target = $request->route('user');

        return ! $target instanceof User || $target->account_id === $account->id;
    }
}
