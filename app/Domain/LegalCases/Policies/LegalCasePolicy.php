<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Policies;

use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\Users\Models\User;

/**
 * Who may do what to a pleading.
 *
 * Pleadings are the account's own working data, so every member of the account
 * reaches them — drafting is the work, not an administrative privilege. The
 * boundary is absolute, as it is for clients: a pleading has no staff-facing
 * screen, so not even a PlatformAdmin crosses into another tenant's. That
 * matters because route-model binding resolves the record before the tenant
 * middleware runs, and for platform staff the query scope is deliberately open
 * — this policy is the only thing standing in the way.
 *
 * No `update` or `delete` yet: nothing writes a pleading until the assembly
 * flow lands.
 */
final class LegalCasePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, LegalCase $legalCase): bool
    {
        return $user->account_id === $legalCase->account_id;
    }

    public function create(User $user): bool
    {
        return true;
    }
}
