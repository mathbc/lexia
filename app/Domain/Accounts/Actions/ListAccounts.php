<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Queries\AccountIndexQuery;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The accounts registry — LexIA staff browsing every tenant.
 */
final class ListAccounts
{
    use AsAction;

    public function __construct(private readonly AccountIndexQuery $query) {}

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('viewAny', Account::class);
    }

    public function asController(ActionRequest $request): Response
    {
        $filters = $request->only(['search', 'type', 'active', 'sort', 'direction']);

        return Inertia::render('accounts/index', [
            'accounts' => $this->query->paginate($filters),
            'filters' => (object) $filters,
            // The filter doubles as the label source for the table, so the
            // platform type has to be in the list even though no form offers it.
            'accountTypes' => AccountType::options(),
            'canCreate' => $request->user()->can('create', Account::class),
        ]);
    }
}
