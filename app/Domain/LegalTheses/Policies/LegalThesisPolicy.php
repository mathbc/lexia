<?php

declare(strict_types=1);

namespace App\Domain\LegalTheses\Policies;

use App\Domain\LegalTheses\Enums\LegalThesisOrigin;
use App\Domain\LegalTheses\Models\LegalThesis;
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
 * `update` exists because one route binds a LegalThesis now — the "Editar" of a
 * thesis the lawyer wrote by hand, UpdateLegalThesis — and it answers a question
 * the pleading's own policy cannot: *which* thesis. Only a manual one. A
 * researched thesis is a reading of an official portal, and rewriting it would
 * leave a citation that no longer says what the portal said; the lawyer who
 * disagrees with it unticks it.
 *
 * Still no `delete`, and that is the design rather than a gap, exactly as on
 * RequirementPolicy. Removing a thesis is unticking it, and the two lists are
 * written whole, through the pleading, by SaveLegalCaseForensicReview — which
 * asks `update` on the LegalCase.
 */
final class LegalThesisPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, LegalThesis $thesis): bool
    {
        return $user->account_id === $thesis->account_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, LegalThesis $thesis): bool
    {
        return $user->account_id === $thesis->account_id
            && $thesis->origin === LegalThesisOrigin::Manual;
    }
}
