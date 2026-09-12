<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Users\Actions\Concerns\ActsWithinAccount;
use App\Domain\Users\Actions\Concerns\ValidatesUser;
use App\Domain\Users\Data\UserData;
use App\Domain\Users\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Adds a user to an account.
 *
 * No password is chosen here: the user is created with an unguessable
 * placeholder and invited to set their own via the standard reset flow. That
 * avoids an admin ever knowing a colleague's credentials.
 */
final class CreateUser
{
    use ActsWithinAccount;
    use AsAction;
    use ValidatesUser;

    public function handle(Account $account, UserData $data): User
    {
        $user = new User([
            ...$data->toArray(),
            'password' => str()->random(64),
            'enabled' => true,
        ]);

        $user->account_id = $account->id;
        $user->save();

        return $user;
    }

    public function authorize(ActionRequest $request): bool
    {
        return $this->withinRoutedAccount($request)
            && $request->user()->can('create', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(ActionRequest $request): array
    {
        return [
            ...$this->userRules(),
            'role' => ['required', Rule::in($this->assignableRoles($request->user()))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return $this->userAttributes();
    }

    /**
     * @return array<string, string>
     */
    public function getValidationMessages(): array
    {
        return [
            'role.in' => 'Você só pode atribuir perfis abaixo do seu.',
        ];
    }

    public function asController(Account $account, ActionRequest $request): RedirectResponse
    {
        // The account comes from the URL, not from the actor: LexIA staff
        // invite people into a tenant that is not their own.
        $user = $this->handle($account, UserData::fromArray($request->validated()));

        // Invitation and email verification both ride on the framework's
        // existing flows rather than a bespoke token.
        event(new Registered($user));
        Password::sendResetLink(['email' => $user->email]);

        return to_route('users.index', $account)
            ->with('success', "Convite enviado para {$user->email}.");
    }
}
