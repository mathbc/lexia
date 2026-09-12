<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Actions\Concerns\ValidatesAccount;
use App\Domain\Accounts\Data\AccountData;
use App\Domain\Accounts\Models\Account;
use App\Domain\Users\Data\UserData;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Enums\UserType;
use App\Domain\Users\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Public sign-up: creates the tenant and its first user in one transaction.
 *
 * This replaces Fortify's /register, which would only create a user and leave
 * it without an account. The first user is always the AccountAdmin — somebody
 * has to be able to administer the account that was just created.
 */
final class RegisterAccountWithOwner
{
    use AsAction;
    use ValidatesAccount;

    public function handle(AccountData $accountData, UserData $ownerData, string $password): User
    {
        return DB::transaction(function () use ($accountData, $ownerData, $password): User {
            $account = Account::create($accountData->toArray());

            $owner = new User([
                ...$ownerData->toArray(),
                'role' => UserRole::AccountAdmin,
                'password' => $password,
                'enabled' => true,
            ]);

            $owner->account_id = $account->id;
            $owner->save();

            return $owner;
        });
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
            'password' => ['required', 'confirmed', Password::defaults()],
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
            'password' => 'senha',
        ];
    }

    public function asController(ActionRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $owner = $this->handle(
            AccountData::fromArray($validated),
            UserData::forOwner($validated),
            $validated['password'],
        );

        event(new Registered($owner));
        Auth::login($owner);

        return to_route('dashboard')
            ->with('success', 'Conta criada. Confirme seu e-mail para liberar todos os recursos.');
    }
}
