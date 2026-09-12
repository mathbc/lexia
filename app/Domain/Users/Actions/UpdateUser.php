<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Domain\Users\Actions\Concerns\ValidatesUser;
use App\Domain\Users\Data\UserData;
use App\Domain\Users\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Edits a user's details.
 *
 * The role is only accepted from an actor authorised to change it; everyone
 * else's submission simply omits it, so a lawyer editing their own profile
 * cannot promote themselves by adding the field by hand.
 */
final class UpdateUser
{
    use AsAction;
    use ValidatesUser;

    public function handle(User $user, UserData $data): User
    {
        $user->update($data->toArray());

        return $user->refresh();
    }

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('update', $request->route('user'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(ActionRequest $request): array
    {
        $target = $request->route('user');
        $rules = $this->userRules(ignoring: $target);

        if ($request->user()->can('changeRole', $target)) {
            $rules['role'] = ['required', Rule::in($this->assignableRoles($request->user()))];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return $this->userAttributes();
    }

    public function asController(User $user, ActionRequest $request): RedirectResponse
    {
        // validated() only contains `role` when the rule above was added, so
        // UserData leaves the role untouched for everyone else.
        $this->handle($user, UserData::fromArray($request->validated()));

        return to_route('users.index')
            ->with('success', 'Usuário atualizado com sucesso.');
    }
}
