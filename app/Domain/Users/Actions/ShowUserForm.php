<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Accounts\Models\Account;
use App\Domain\Users\Actions\Concerns\ActsWithinAccount;
use App\Domain\Users\Actions\Concerns\ValidatesUser;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Enums\UserType;
use App\Domain\Users\Models\User;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Renders the create/edit user form for an account.
 *
 * The role options come from assignableRoles(), so the dropdown can never
 * offer a role the actor is not allowed to grant — the same list the
 * validation rule enforces.
 */
final class ShowUserForm
{
    use ActsWithinAccount;
    use AsAction;
    use ValidatesUser;

    public function authorize(ActionRequest $request): bool
    {
        if (! $this->withinRoutedAccount($request)) {
            return false;
        }

        $target = $request->route('user');

        return $target instanceof User
            ? $request->user()->can('update', $target)
            : $request->user()->can('create', User::class);
    }

    public function asController(Account $account, ActionRequest $request, ?User $user = null): Response
    {
        $actor = $request->user();
        $assignable = $this->assignableRoles($actor);

        return Inertia::render($user instanceof User ? 'users/edit' : 'users/create', [
            'account' => ['id' => $account->id, 'name' => $account->displayName()],
            'user' => $user,
            'canChangeRole' => $user instanceof User
                ? $actor->can('changeRole', $user)
                : true,
            'roles' => $this->roleOptions($assignable),
            'types' => UserType::options(),
            'states' => BrazilianState::options(),
        ]);
    }

    /**
     * @param  list<string>  $assignable
     * @return list<array{value: string, label: string}>
     */
    private function roleOptions(array $assignable): array
    {
        return array_values(array_filter(
            UserRole::options(),
            static fn (array $option): bool => in_array($option['value'], $assignable, true),
        ));
    }
}
