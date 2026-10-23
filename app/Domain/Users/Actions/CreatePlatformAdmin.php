<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Enums\UserType;
use App\Domain\Users\Models\User;
use Illuminate\Validation\Rules\Password;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * A LexIA staff member, created from the console by `lexia:install`.
 *
 * The one user no screen can create: the panel's user form offers only the
 * roles inside an account, and the first platform admin has to exist before
 * anyone can sign in to make the others. So the password is chosen here,
 * instead of going through the invitation CreateUser sends — a fresh install
 * may not have mail configured yet — and the e-mail is taken as verified,
 * since every route behind the login also asks for that.
 */
final class CreatePlatformAdmin
{
    use AsAction;

    public function handle(string $name, string $email, string $password): User
    {
        $user = new User([
            'name' => $name,
            'email' => self::normalizeEmail($email),
            'password' => $password,
            'role' => UserRole::PlatformAdmin,
            'type' => UserType::Lawyer,
            'enabled' => true,
        ]);

        $user->account_id = Account::PLATFORM_ID;
        $user->email_verified_at = now();
        $user->save();

        return $user;
    }

    /**
     * The same normalisation UserData applies, so the uniqueness check and the
     * login both see the address the way it was stored.
     */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'name' => 'nome',
            'email' => 'e-mail',
            'password' => 'senha',
        ];
    }
}
