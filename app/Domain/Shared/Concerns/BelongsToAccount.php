<?php

declare(strict_types=1);

namespace App\Domain\Shared\Concerns;

use App\Domain\Accounts\Models\Account;
use App\Domain\Shared\Scopes\AccountScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For models owned by a tenant.
 *
 * Applies AccountScope and fills `account_id` on create, so a use case can
 * never leak a row into the wrong account by forgetting to set it.
 */
trait BelongsToAccount
{
    public static function bootBelongsToAccount(): void
    {
        static::addGlobalScope(new AccountScope);

        static::creating(function (self $model): void {
            $model->account_id ??= auth()->user()?->account_id;
        });
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Escape hatch for console commands, queued jobs and tenant-wide reports.
     *
     * Named so that it is obvious in review when the tenant boundary is being
     * crossed on purpose.
     *
     * @return Builder<static>
     */
    public static function acrossAllAccounts(): Builder
    {
        return static::query()->withoutGlobalScope(AccountScope::class);
    }
}
