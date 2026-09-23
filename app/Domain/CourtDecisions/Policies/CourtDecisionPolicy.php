<?php

declare(strict_types=1);

namespace App\Domain\CourtDecisions\Policies;

use App\Domain\CourtDecisions\Models\CourtDecision;
use App\Domain\Users\Models\User;

/**
 * Who may do what to the case law a pleading leans on.
 *
 * It follows the pleading's own policy, because it is part of it: every member
 * of the account reaches it — researching the case is the work, not an
 * administrative privilege — and the boundary is absolute. Not even a
 * PlatformAdmin crosses into another tenant's, since the jurisprudence analysis
 * has no staff-facing screen.
 *
 * That matters for the same reason it matters on LegalCasePolicy: route-model
 * binding resolves the record before the tenant middleware runs, and for
 * platform staff the query scope is deliberately open — this policy is the only
 * thing standing in the way once a route exists.
 *
 * No `update` and no `delete`, exactly as on LegalPrecedentPolicy, and for the
 * same reason: no route binds a CourtDecision. The list is written whole,
 * through the pleading, by the save that arrives with the screen — which asks
 * `update` on the LegalCase. The authority over one row is the authority over
 * the pleading that cites it, and writing a second gate here would be dead code
 * implying a route that does not exist.
 */
final class CourtDecisionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CourtDecision $decision): bool
    {
        return $user->account_id === $decision->account_id;
    }

    public function create(User $user): bool
    {
        return true;
    }
}
