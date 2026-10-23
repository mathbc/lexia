<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Accounts\Models\Account;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Makes sure LexIA's own account is there, for `lexia:install`.
 *
 * The migration that creates it is still the source: every database that
 * migrates has it, the tests included. This is the safety net for one that
 * lost it — a manual delete, a restore that skipped a row —, since without it
 * no platform admin can be created. A soft-deleted one is restored rather than
 * duplicated: the id is fixed, and the admins hang off it.
 *
 * The attributes repeat the migration's. They are written through the model
 * here, and through the query builder there, for the reason that migration
 * gives: a migration has to keep working when the model changes under it.
 */
final class EnsurePlatformAccount
{
    use AsAction;

    public function handle(): Account
    {
        $account = Account::withoutGlobalScopes()->find(Account::PLATFORM_ID);

        if ($account === null) {
            return $this->create();
        }

        if ($account->trashed()) {
            $account->restore();
        }

        return $account;
    }

    private function create(): Account
    {
        $account = new Account;

        // forceFill: the id is guarded, and here it is the whole point.
        $account->forceFill([
            'id' => Account::PLATFORM_ID,
            'name' => 'LexIA',
            'type' => AccountType::Platform,
            'email' => 'suporte@lexia.com.br',
            'phone' => '00000000000',
            'postal_code' => '00000000',
            'street' => 'A definir',
            'number' => 'S/N',
            'district' => 'A definir',
            'city' => 'A definir',
            'state' => BrazilianState::SP,
            'active' => true,
            'enabled' => true,
        ])->save();

        return $account;
    }
}
