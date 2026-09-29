<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Queries\Concerns;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Models\Account;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The one rule every dashboard count shares: an account, or every customer
 * account at once.
 *
 * The account is named explicitly, like the index queries do, because for
 * platform staff the AccountScope is open. Null is staff looking at the whole
 * platform, and "whole" means the customers: the LexIA account holds the staff
 * themselves, who would otherwise inflate the users count, and an account that
 * was deleted takes its rows out of the sums along with itself.
 */
trait ConstrainsToAccount
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function withinAccount(Builder $query, ?string $accountId): Builder
    {
        return $accountId === null
            ? $query->whereIn('account_id', self::customerAccounts()->select('id'))
            : $query->where('account_id', $accountId);
    }

    /**
     * Staff see every account through VisibleAccountScope; anyone else would
     * see only their own, which is the safe way for this to fail.
     *
     * @return Builder<Account>
     */
    private static function customerAccounts(): Builder
    {
        return Account::query()->whereIn('type', AccountType::customerValues());
    }
}
