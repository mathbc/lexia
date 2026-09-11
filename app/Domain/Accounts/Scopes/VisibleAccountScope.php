<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Scopes;

use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * An account can only see itself.
 *
 * The sibling AccountScope filters tenant-owned models by `account_id`; the
 * accounts table has no such column, because here the tenant *is* the row.
 */
final class VisibleAccountScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->shouldScope()) {
            return;
        }

        $builder->whereKey($context->accountId());
    }
}
