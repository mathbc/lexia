<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Support;

use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\Users\Models\User;

/**
 * What the pleading screens may offer the actor.
 *
 * The listing publishes one set of abilities rather than one per card: every
 * pleading on the screen belongs to the actor's own account, so the Policy's
 * answer is the same for all of them. An unsaved stand-in carries the account
 * when there is no row to ask about, which keeps LegalCasePolicy the only place
 * the rule is written down.
 */
final class LegalCasePageProps
{
    /**
     * @return array<string, bool>
     */
    public static function abilities(User $actor, ?LegalCase $legalCase = null): array
    {
        $subject = $legalCase ?? new LegalCase(['account_id' => $actor->account_id]);

        return [
            'view' => $actor->can('view', $subject),
            'create' => $actor->can('create', LegalCase::class),
        ];
    }
}
