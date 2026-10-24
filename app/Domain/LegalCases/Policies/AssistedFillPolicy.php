<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Policies;

use App\Domain\LegalCases\Models\AssistedFill;
use App\Domain\Users\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may watch a smart fill: the person who asked for it.
 *
 * Narrower than the pleading's policy, which opens to the whole account. A fill
 * is not yet anybody's pleading — it is one person's narrative on its way to
 * becoming one —, and its id travels in a query string. Denied as not found,
 * so that an id from someone else's address bar does not even confirm the row
 * exists.
 */
final class AssistedFillPolicy
{
    public function view(User $user, AssistedFill $assistedFill): Response
    {
        return $user->id === $assistedFill->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
