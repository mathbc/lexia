<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Support\AccountPageProps;
use App\Domain\Users\Actions\Concerns\ActsWithinAccount;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Enums\UserType;
use App\Domain\Users\Models\User;
use App\Domain\Users\Queries\UserIndexQuery;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The "Usuários" tab of an account.
 */
final class ListUsers
{
    use ActsWithinAccount;
    use AsAction;

    public function __construct(private readonly UserIndexQuery $query) {}

    public function authorize(ActionRequest $request): bool
    {
        return $this->withinRoutedAccount($request)
            && $request->user()->can('viewAny', User::class);
    }

    public function asController(Account $account, ActionRequest $request): Response
    {
        $filters = $request->only(['search', 'role', 'type', 'enabled', 'sort', 'direction']);

        return Inertia::render('accounts/users', [
            ...AccountPageProps::for($account, $request->user()),
            'users' => $this->query->paginate($account, $filters),
            'filters' => (object) $filters,
            // The filter doubles as the label source for the table, so staff
            // browsing their own account need the platform role in the list too.
            'roles' => $account->isPlatform()
                ? UserRole::options()
                : UserRole::accountOptions(),
            'types' => UserType::options(),
            'canCreate' => $request->user()->can('create', User::class),
        ]);
    }
}
