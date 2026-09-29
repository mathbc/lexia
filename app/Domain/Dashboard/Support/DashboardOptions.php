<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Support;

use App\Domain\Accounts\Models\Account;
use App\Domain\Users\Models\User;

/**
 * The select lists of the dashboard's filter panel: accounts, for LexIA staff,
 * and the users of whichever account is in view.
 */
final class DashboardOptions
{
    /**
     * Every account, the LexIA one among them: staff open pleadings there too,
     * and the chart should be able to show them.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function accounts(): array
    {
        return Account::query()
            // The same name displayName() picks, so the order matches the labels.
            ->orderByRaw('coalesce(legal_name, name)')
            ->get()
            ->map(static fn (Account $account): array => [
                'value' => $account->id,
                'label' => $account->displayName(),
            ])
            ->all();
    }

    /**
     * Disabled users included: they still opened pleadings, and the chart
     * should be able to show them.
     *
     * Null — staff with no account chosen — is an empty list rather than every
     * user on the platform: the filter asks for an account first.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function users(?string $accountId): array
    {
        if ($accountId === null) {
            return [];
        }

        return User::acrossAllAccounts()
            ->where('account_id', $accountId)
            ->orderBy('name')
            ->get()
            ->map(static fn (User $user): array => [
                'value' => $user->id,
                'label' => $user->name,
            ])
            ->all();
    }
}
