<?php

declare(strict_types=1);

namespace App\Domain\Customers\Support;

use App\Domain\Customers\Models\Customer;
use App\Domain\Users\Models\User;

/**
 * What the client screens may offer the actor.
 *
 * The listing publishes one set of abilities rather than one per row: every
 * client on the screen belongs to the actor's own account, so the Policy's
 * answer is the same for all of them. An unsaved stand-in carries the account
 * when there is no row to ask about, which keeps CustomerPolicy the only place
 * the rule is written down.
 */
final class CustomerPageProps
{
    /**
     * @return array<string, bool>
     */
    public static function abilities(User $actor, ?Customer $customer = null): array
    {
        $subject = $customer ?? new Customer(['account_id' => $actor->account_id]);

        return [
            'update' => $actor->can('update', $subject),
            'delete' => $actor->can('delete', $subject),
        ];
    }
}
