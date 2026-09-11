<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Policies;

use App\Domain\Accounts\Models\Account;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;

/**
 * Who may do what to an account.
 *
 * Every ability first checks that the actor belongs to the account in
 * question. The global scope already hides other tenants, but authorisation
 * must not depend on a query scope having been applied.
 */
final class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPlatformAdmin();
    }

    public function view(User $user, Account $account): bool
    {
        return $this->belongsTo($user, $account);
    }

    /**
     * Only LexIA staff create tenants directly; everyone else arrives through
     * public sign-up, which is not authorised through this policy.
     */
    public function create(User $user): bool
    {
        return $user->isPlatformAdmin();
    }

    public function update(User $user, Account $account): bool
    {
        return $this->belongsTo($user, $account)
            && $user->role === UserRole::AccountAdmin;
    }

    public function toggleStatus(User $user, Account $account): bool
    {
        return $this->update($user, $account);
    }

    /**
     * Deleting a tenant is destructive and irreversible from the UI; keep it
     * with LexIA staff.
     */
    public function delete(User $user, Account $account): bool
    {
        return $user->isPlatformAdmin();
    }

    private function belongsTo(User $user, Account $account): bool
    {
        return $user->isPlatformAdmin() || $user->account_id === $account->id;
    }
}
