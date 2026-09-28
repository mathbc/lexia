<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Shared\Concurrency\IsolatedProcessDriver;
use App\Domain\Shared\Tenancy\TenantContext;
use App\Rag\KnowledgeBase;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One tenant per request/job. The scopes read it; middleware sets it.
        $this->app->singleton(TenantContext::class);

        // The agents' knowledge documents are read from disk once per
        // process, not once per prompt.
        $this->app->singleton(KnowledgeBase::class);
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

        Date::use(CarbonImmutable::class);

        // O `process` do framework dá 60 s a cada task e, quando uma estoura,
        // descarta as que já tinham voltado. Não é estática porque o manager
        // faz `bindTo()` na closure.
        Concurrency::extend('process', fn (Application $app): IsolatedProcessDriver => new IsolatedProcessDriver(
            $app->make(ProcessFactory::class),
            (int) config('concurrency.timeout'),
        ));
    }
}
