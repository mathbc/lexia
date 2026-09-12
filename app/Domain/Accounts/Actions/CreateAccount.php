<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Actions\Concerns\ValidatesAccount;
use App\Domain\Accounts\Data\AccountData;
use App\Domain\Accounts\Models\Account;
use App\Domain\Users\Data\UserData;
use App\Domain\Users\Enums\UserType;
use App\Domain\Users\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * LexIA staff opening a tenant from the panel.
 *
 * An account with no administrator could never be administered, so the first
 * AccountAdmin is created with it — the same pairing public sign-up makes,
 * which is why the creation itself is delegated rather than repeated. No
 * password is chosen here: the owner is invited through the reset flow, as
 * CreateUser does for every other user.
 */
final class CreateAccount
{
    use AsAction;
    use ValidatesAccount;

    public function handle(AccountData $accountData, UserData $ownerData): User
    {
        return RegisterAccountWithOwner::make()->handle(
            $accountData,
            $ownerData,
            str()->random(64),
        );
    }

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('create', Account::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->accountRules(),
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'owner_birth_date' => ['nullable', 'date', 'before:today'],
            'owner_type' => ['required', Rule::enum(UserType::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return [
            ...$this->accountAttributes(),
            'owner_name' => 'nome do responsável',
            'owner_email' => 'e-mail do responsável',
            'owner_birth_date' => 'data de nascimento',
            'owner_type' => 'tipo de usuário',
        ];
    }

    public function asController(ActionRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $owner = $this->handle(
            AccountData::fromArray($validated),
            UserData::forOwner($validated),
        );

        event(new Registered($owner));
        Password::sendResetLink(['email' => $owner->email]);

        return to_route('accounts.show', $owner->account_id)
            ->with('success', "Conta criada. Convite enviado para {$owner->email}.");
    }
}
