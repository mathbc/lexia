<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Shared\Tenancy\TenantContext;
use App\Domain\Users\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pins the request to the signed-in user's account.
 *
 * Must run after authentication. Until it does, TenantContext is empty and
 * tenant-owned models are unscoped — which is what lets the session guard load
 * the authenticated user in the first place.
 */
final class BindTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            // Platform staff see every tenant; everyone else is pinned to theirs.
            $user->isPlatformAdmin()
                ? $this->context->allowAllAccounts()
                : $this->context->set($user->account_id);
        }

        return $next($request);
    }
}
