<?php

declare(strict_types=1);

namespace App\Domain\Shared\Tenancy;

use Closure;

/**
 * The account the current request, job or command is acting on behalf of.
 *
 * Resolving the tenant from the auth guard directly would recurse: loading the
 * authenticated User is itself a query, which the scope would try to filter by
 * asking the guard for a user. A singleton set once by middleware breaks that
 * cycle and, more importantly, makes the tenant boundary an explicit object
 * that tests and queued jobs can set deliberately.
 */
final class TenantContext
{
    private ?string $accountId = null;

    /** Set when platform staff are deliberately working across tenants. */
    private bool $unrestricted = false;

    public function set(?string $accountId): void
    {
        $this->accountId = $accountId;
        $this->unrestricted = false;
    }

    public function allowAllAccounts(): void
    {
        $this->accountId = null;
        $this->unrestricted = true;
    }

    public function clear(): void
    {
        $this->accountId = null;
        $this->unrestricted = false;
    }

    public function accountId(): ?string
    {
        return $this->accountId;
    }

    /**
     * Whether queries should be filtered right now.
     *
     * False both when nobody is signed in (login, console, queue) and when
     * platform staff have opted out.
     */
    public function shouldScope(): bool
    {
        return ! $this->unrestricted && $this->accountId !== null;
    }

    /**
     * Run a callback as the given account, restoring the previous state after.
     *
     * This is how a queued job or console command adopts a tenant.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function actingAs(?string $accountId, Closure $callback): mixed
    {
        $previousAccount = $this->accountId;
        $previousUnrestricted = $this->unrestricted;

        $this->set($accountId);

        try {
            return $callback();
        } finally {
            $this->accountId = $previousAccount;
            $this->unrestricted = $previousUnrestricted;
        }
    }
}
