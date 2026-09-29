<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Actions\Concerns\ValidatesAccount;
use App\Domain\Accounts\Data\AccountData;
use App\Domain\Accounts\Data\AccountLogosData;
use App\Domain\Accounts\Models\Account;
use App\Domain\Users\Actions\SendUserInvitation;
use App\Domain\Users\Data\UserData;
use App\Domain\Users\Enums\UserType;
use App\Domain\Users\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
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
 *
 * The logos are stored inside the same transaction. They need the account's
 * id for their folder, so they come after it; and a logo that failed to store
 * must not leave behind an account whose CNPJ would then refuse the retry.
 */
final class CreateAccount
{
    use AsAction;
    use ValidatesAccount;

    public function handle(AccountData $accountData, UserData $ownerData, AccountLogosData $logos): User
    {
        return DB::transaction(function () use ($accountData, $ownerData, $logos): User {
            $owner = RegisterAccountWithOwner::make()->handle(
                $accountData,
                $ownerData,
                str()->random(64),
            );

            SaveAccountLogos::make()->handle($owner->account, $logos);

            return $owner;
        });
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
            ...$this->logoRules(),
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
            ...$this->logoAttributes(),
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
            AccountLogosData::fromArray($validated),
        );

        // The invitation replaces the framework's verification e-mail; see
        // CreateUser for why the `Registered` event is not fired here.
        SendUserInvitation::run($owner);

        Inertia::flash('success', "Conta criada. Convite enviado para {$owner->email}.");

        return to_route('accounts.show', $owner->account_id);
    }
}
