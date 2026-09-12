<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

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
        return $request->user()->can('toggleStatus', $request->route('user'));
    }

    public function asController(User $user): RedirectResponse
    {
        $this->handle($user);

        return back()->with(
            'success',
            $user->enabled ? 'Usuário habilitado.' : 'Usuário desabilitado.',
        );
    }

    /**
     * An account with no enabled AccountAdmin can never be administered again,
     * so the last one cannot be switched off.
     */
    private function guardAgainstRemovingLastAdmin(User $user): void
    {
        if ($user->role !== UserRole::AccountAdmin) {
            return;
        }

        $remaining = User::query()
            ->where('account_id', $user->account_id)
            ->where('role', UserRole::AccountAdmin)
            ->where('enabled', true)
            ->whereKeyNot($user->getKey())
            ->count();

        if ($remaining === 0) {
            throw ValidationException::withMessages([
                'enabled' => 'A conta precisa de pelo menos um Admin da Conta habilitado.',
            ]);
        }
    }
}
