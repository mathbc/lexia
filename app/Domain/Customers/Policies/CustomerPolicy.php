<?php

declare(strict_types=1);

namespace App\Domain\Customers\Policies;

use App\Domain\Customers\Models\Customer;
use App\Domain\Users\Models\User;

/**
 * Who may do what to a client.
 *
 * Clients are the account's own working data, so every member of the account
 * reaches them — a lawyer who cannot open their own client list has nothing to
 * work on. The boundary is what is guarded here, and it is absolute: unlike
 * accounts and users, a client has no staff-facing screen, so not even a
 * PlatformAdmin crosses into another tenant's clients. That matters because
 * route-model binding resolves the client before the tenant middleware runs,
 * and for platform staff the query scope is deliberately open — this policy is
 * the only thing standing in the way.
 */
final class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->sameAccount($user, $customer);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->sameAccount($user, $customer);
    }

    /**
     * Deleting a client takes their whole history with them, so it stays with
     * whoever manages the account rather than with every lawyer in it.
     */
    public function delete(User $user, Customer $customer): bool
    {
        return $this->sameAccount($user, $customer) && $user->role->manages();
    }

    private function sameAccount(User $user, Customer $customer): bool
    {
        return $user->account_id === $customer->account_id;
    }
}
