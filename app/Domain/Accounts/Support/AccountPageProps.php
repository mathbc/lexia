<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Support;

use App\Domain\Accounts\Models\Account;
use App\Domain\Users\Models\User;

/**
 * The props every tab of the account screen needs.
 *
 * "Dados gerais" and "Usuários" are two Inertia pages behind one set of tabs,
 * so the header and the permissions that drive it are built here once instead
 * of drifting between the two Actions that render them.
 */
final class AccountPageProps
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Account $account, User $actor): array
    {
        return [
            'account' => $account,
            'can' => [
                'update' => $actor->can('update', $account),
                'toggle_status' => $actor->can('toggleStatus', $account),
                'manage_users' => $actor->can('viewAny', User::class),
            ],
        ];
    }
}
