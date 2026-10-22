<?php

declare(strict_types=1);

namespace App\Domain\Doctrines\Policies;

use App\Domain\Doctrines\Models\Doctrine;
use App\Domain\Users\Models\User;

/**
 * Who may do what to the scholarship a pleading leans on.
 *
 * CourtDecisionPolicy, word for word, and for its reasons: every member of the
 * account reaches the doctrine of its pleadings, and the boundary is absolute —
 * not even a PlatformAdmin crosses it, since the forensic review has no
 * staff-facing screen.
 *
 * No `update` and no `delete`: no route binds a Doctrine. The list is written
 * whole, through the pleading, by an Action that asks `update` on the
 * LegalCase.
 */
final class DoctrinePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Doctrine $doctrine): bool
    {
        return $user->account_id === $doctrine->account_id;
    }

    public function create(User $user): bool
    {
        return true;
    }
}
