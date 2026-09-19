<?php

declare(strict_types=1);

namespace App\Domain\LegalPrecedents\Policies;

use App\Domain\LegalPrecedents\Models\LegalPrecedent;
use App\Domain\Users\Models\User;

/**
 * Who may do what to part of a pleading's forensic review.
 *
 * It follows the pleading's own policy, because it is part of it: every member
 * of the account reaches it — arguing the case is the work, not an
 * administrative privilege — and the boundary is absolute. Not even a
 * PlatformAdmin crosses into another tenant's, since the forensic review has no
 * staff-facing screen.
 *
 * That matters for the same reason it matters on LegalCasePolicy: route-model
 * binding resolves the record before the tenant middleware runs, and for
 * platform staff the query scope is deliberately open — this policy is the only
 * thing standing in the way once a route exists.
 *
 * No `update` and no `delete`, and that is the design rather than a gap, exactly
 * as on RequirementPolicy. No route ever binds a LegalPrecedent: the two lists are
 * written whole, through the pleading, by SaveLegalCaseForensicReview — which
 * asks `update` on the LegalCase. The authority over one row is the authority
 * over the pleading that argues it, and writing a second gate here would be dead
 * code implying a route that does not exist.
 */
final class LegalPrecedentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, LegalPrecedent $precedent): bool
    {
        return $user->account_id === $precedent->account_id;
    }

    public function create(User $user): bool
    {
        return true;
    }
}
