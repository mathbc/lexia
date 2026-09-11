<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One tenant per request/job. The scopes read it; middleware sets it.
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        // Fail loudly in development instead of silently returning null for a
        // relation that was never loaded, or silently dropping an attribute
        // that is not fillable.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Guarded models are the convention here; mass assignment is filtered
        // by the Actions' validation rules, not by $fillable lists.
        Model::automaticallyEagerLoadRelationships();

        Date::use(\Carbon\CarbonImmutable::class);
    }
}
