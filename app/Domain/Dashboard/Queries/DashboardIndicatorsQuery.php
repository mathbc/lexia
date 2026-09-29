<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Queries;

use App\Domain\Dashboard\Queries\Concerns\ConstrainsToAccount;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\Users\Models\User;

/**
 * The indicator cards: pleadings finished and in draft, users, and — for LexIA
 * staff only — the size of the platform.
 */
final class DashboardIndicatorsQuery
{
    use ConstrainsToAccount;

    /**
     * @return array{finalized: int, drafts: int, users: int}
     */
    public function forAccount(?string $accountId): array
    {
        // One pass over the table rather than two counts: the draft flag is
        // the only thing that splits them.
        $cases = $this->withinAccount(LegalCase::acrossAllAccounts(), $accountId)
            ->toBase()
            ->selectRaw('count(*) filter (where not is_draft) as finalized')
            ->selectRaw('count(*) filter (where is_draft) as drafts')
            ->first();

        return [
            'finalized' => (int) ($cases->finalized ?? 0),
            'drafts' => (int) ($cases->drafts ?? 0),
            'users' => $this->withinAccount(User::acrossAllAccounts(), $accountId)->count(),
        ];
    }

    /**
     * The customer accounts, with the pleadings and users inside them. The
     * caller decides who may see this; the query only knows the platform
     * account is not a customer.
     *
     * @return array{accounts: int, legal_cases: int, users: int}
     */
    public function platform(): array
    {
        return [
            'accounts' => self::customerAccounts()->count(),
            'legal_cases' => $this->withinAccount(LegalCase::acrossAllAccounts(), null)->count(),
            'users' => $this->withinAccount(User::acrossAllAccounts(), null)->count(),
        ];
    }
}
