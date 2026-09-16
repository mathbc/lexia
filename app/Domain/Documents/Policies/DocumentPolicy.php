<?php

declare(strict_types=1);

namespace App\Domain\Documents\Policies;

use App\Domain\Documents\Models\Document;
use App\Domain\Users\Models\User;

/**
 * Who may do what to a document that instructs a pleading.
 *
 * It follows the pleading's own policy, because it is part of it: every member
 * of the account reaches the attachments — assembling them is the work, not an
 * administrative privilege — and the boundary is absolute. Not even a
 * PlatformAdmin crosses into another tenant's, since documents have no
 * staff-facing screen.
 *
 * That matters for the same reason it matters on LegalCasePolicy: route-model
 * binding resolves the record before the tenant middleware runs, and for
 * platform staff the query scope is deliberately open — this policy is the only
 * thing standing in the way once a route exists.
 *
 * No `update` or `delete` yet: nothing writes a document until the upload flow
 * lands, and the form keeps its files in the browser until then.
 */
final class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Document $document): bool
    {
        return $user->account_id === $document->account_id;
    }

    public function create(User $user): bool
    {
        return true;
    }
}
