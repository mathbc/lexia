<?php

declare(strict_types=1);

namespace App\Domain\Users\Policies;

use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;

/**
 * Who may do what to a user.
 *
 * Two invariants run through all of it:
 *  - nobody acts outside their own account;
 *  - nobody manages a peer or a superior, so two admins cannot lock each
 *    other out. Both live in User::canManage().
 */
final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->manages() || $user->isPlatformAdmin();
    }

    public function view(User $user, User $target): bool
    {
        if ($user->is($target)) {
            return true;
        }

        return $this->sameAccount($user, $target) && $user->role->manages();
    }

    public function create(User $user): bool
    {
        return $user->role->manages() || $user->isPlatformAdmin();
    }

    /**
     * A user may always edit their own profile; managing someone else requires
     * outranking them.
     */
    public function update(User $user, User $target): bool
    {
        return $user->is($target) || $user->canManage($target);
    }

    public function delete(User $user, User $target): bool
    {
        // Deleting yourself would orphan the session and, for the last
        // account admin, the account itself.
        if ($user->is($target)) {
            return false;
        }

        return $user->canManage($target);
    }

    /**
     * Changing a role is an escalation risk, so it sits with the account owner.
     */
    public function changeRole(User $user, User $target): bool
    {
        if ($user->is($target)) {
            return false;
        }

        return $user->isPlatformAdmin()
            || ($this->sameAccount($user, $target) && $user->role === UserRole::AccountAdmin);
    }

    public function toggleStatus(User $user, User $target): bool
    {
        return $this->delete($user, $target);
    }

    private function sameAccount(User $user, User $target): bool
    {
        return $user->isPlatformAdmin() || $user->account_id === $target->account_id;
    }
}
