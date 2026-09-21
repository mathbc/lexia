<?php

declare(strict_types=1);

use App\Http\Middleware\AllowLongInference;
use App\Http\Middleware\BindTenantContext;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Order matters: BindTenantContext reads the authenticated user, so it
        // must come after the session and authentication middleware that the
        // web group already provides.
        $middleware->web(append: [
            BindTenantContext::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Nas rotas, e não no grupo `web`: só as quatro que esperam por um
        // agente precisam do teto de execução levantado, e dizê-lo na rota é
        // o que torna visível quais são elas.
        $middleware->alias([
            'inference' => AllowLongInference::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
