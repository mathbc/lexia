<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Users\Actions\Concerns\ActsWithinAccount;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Enables or disables a user's access.
 *
 * Disabling is preferred over deletion: a lawyer who leaves a firm still has
 * their name attached to work already done.
 */
final class ToggleUserStatus
{
    use ActsWithinAccount;
    use AsAction;

    public function handle(User $user): User
    {
        if ($user->enabled) {
            $this->guardAgainstRemovingLastAdmin($user);
        }

        $user->update(['enabled' => ! $user->enabled]);

        return $user;
    }

    public function authorize(ActionRequest $request): bool
    {
        return $this->withinRoutedAccount($request)
            && $request->user()->can('toggleStatus', $request->route('user'));
    }

    /**
     * The account is type-hinted only so route-model binding resolves it:
     * without that, authorize() would receive a bare id and could not check
     * that the user in the URL really belongs to the account in the URL.
     */
    public function asController(Account $account, User $user): RedirectResponse
    {
        $this->handle($user);

        return back()->with(
            'success',
            $user->enabled ? 'Usuário habilitado.' : 'Usuário desabilitado.',
        );
    }

    /**
     * An account with no enabled admin can never be administered again, so the
     * last one cannot be switched off. That holds for LexIA's own account as
     * much as for a customer's: disabling the last platform admin would leave
     * nobody able to administer the platform.
     *
     * @var list<UserRole>
     */
    private const array IRREPLACEABLE_ROLES = [
        UserRole::PlatformAdmin,
        UserRole::AccountAdmin,
    ];

    private function guardAgainstRemovingLastAdmin(User $user): void
    {
        if (! in_array($user->role, self::IRREPLACEABLE_ROLES, true)) {
            return;
        }

        $remaining = User::query()
            ->where('account_id', $user->account_id)
            ->where('role', $user->role)
            ->where('enabled', true)
            ->whereKeyNot($user->getKey())
            ->count();

        if ($remaining === 0) {
            throw ValidationException::withMessages([
                'enabled' => "A conta precisa de pelo menos um {$user->role->label()} habilitado.",
            ]);
        }
    }
}
