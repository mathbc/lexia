<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Queries\Concerns;

use App\Domain\Accounts\Models\Account;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The one rule every dashboard count shares: an account, or every account at
 * once.
 *
 * The account is named explicitly, like the index queries do, because for
 * platform staff the AccountScope is open. Null is staff looking at the whole
 * platform, and the LexIA account is part of it like any other: its type
 * decides who may cross the tenant boundary, not whether its pleadings count.
 * An account that was deleted takes its rows out of the sums along with itself.
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
            ? $query->whereIn('account_id', self::accounts()->select('id'))
            : $query->where('account_id', $accountId);
    }

    /**
     * Staff see every account through VisibleAccountScope; anyone else would
     * see only their own, which is the safe way for this to fail.
     *
     * @return Builder<Account>
     */
    private static function accounts(): Builder
    {
        return Account::query();
    }
}
