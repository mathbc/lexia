<?php

declare(strict_types=1);

namespace App\Domain\Shared\Scopes;

use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Confines every query on a tenant-owned model to the current account.
 *
 * Reads the tenant from TenantContext rather than the auth guard — see that
 * class for why. When no tenant is set (login, queued jobs, console) the scope
 * does nothing, so background work must adopt a tenant explicitly via
 * TenantContext::actingAs().
 */
final class AccountScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->shouldScope()) {
            return;
        }

        $builder->where($model->qualifyColumn('account_id'), $context->accountId());
    }
}
