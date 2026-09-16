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
 * `update` is the gate of the whole assembly flow: the six Actions that save
 * one step each all ask it, and so does the saving of the requests, which are
 * written through the pleading rather than addressed on their own. It asks
 * nothing about the role — drafting is the work, not an administrative
 * privilege — and nothing about `is_draft`: no Action closes a pleading yet, so
 * a rule refusing to edit a finished one would be guarding a state that cannot
 * occur. That check arrives with the Action that finalises.
 *
 * No `delete` yet: nothing removes a pleading.
 */
final class LegalCasePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, LegalCase $legalCase): bool
    {
        return $this->sameAccount($user, $legalCase);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, LegalCase $legalCase): bool
    {
        return $this->sameAccount($user, $legalCase);
    }

    /**
     * The boundary, written once: a pleading is reachable only from inside the
     * account that drafted it, whoever is asking.
     */
    private function sameAccount(User $user, LegalCase $legalCase): bool
    {
        return $user->account_id === $legalCase->account_id;
    }
}
