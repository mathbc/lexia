<?php

declare(strict_types=1);

namespace App\Domain\Requirements\Policies;

use App\Domain\Requirements\Models\Requirement;
use App\Domain\Users\Models\User;

/**
 * Who may do what to a request a pleading makes.
 *
 * It follows the pleading's own policy, because it is part of it: every member
 * of the account reaches the requests — writing them is the work, not an
 * administrative privilege — and the boundary is absolute. Not even a
 * PlatformAdmin crosses into another tenant's, since requests have no
 * staff-facing screen.
 *
 * That matters for the same reason it matters on LegalCasePolicy: route-model
 * binding resolves the record before the tenant middleware runs, and for
 * platform staff the query scope is deliberately open — this policy is the only
 * thing standing in the way once a route exists.
 *
 * No `update` and no `delete`, and that is the design rather than a gap. No
 * route ever binds a Requirement: the list is written whole, through the
 * pleading, by SaveLegalCaseRequirements — which asks `update` on the
 * LegalCase. The authority over a request is the authority over the pleading
 * that makes it, and writing a second gate here would be dead code implying a
 * route that does not exist.
 */
final class RequirementPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Requirement $requirement): bool
    {
        return $user->account_id === $requirement->account_id;
    }

    public function create(User $user): bool
    {
        return true;
    }
}
