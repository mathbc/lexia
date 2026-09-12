<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions\Concerns;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Shared\Rules\OabNumber;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Enums\UserType;
use App\Domain\Users\Models\User;
use Illuminate\Validation\Rule;

trait ValidatesUser
{
    /**
     * @return array<string, mixed>
     */
    protected function userRules(?User $ignoring = null): array
    {
        $email = Rule::unique('users', 'email')->whereNull('deleted_at');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email:rfc',
                'max:255',
                $ignoring === null ? $email : $email->ignore($ignoring),
            ],
            'type' => ['required', Rule::enum(UserType::class)],
            'oab_number' => ['nullable', new OabNumber],
            'oab_state' => ['nullable', 'required_with:oab_number', Rule::enum(BrazilianState::class)],
            'birth_date' => ['nullable', 'date', 'before:today'],
        ];
    }

    /**
     * Which roles the actor is allowed to hand out.
     *
     * A user may only assign roles strictly below their own, so an Admin
     * cannot mint another Admin and quietly gain a peer.
     *
     * @return list<string>
     */
    protected function assignableRoles(User $actor): array
    {
        if ($actor->isPlatformAdmin()) {
            return array_column(UserRole::cases(), 'value');
        }

        return array_values(array_map(
            static fn (UserRole $role): string => $role->value,
            array_filter(
                UserRole::cases(),
                static fn (UserRole $role): bool => $actor->role->outranks($role),
            ),
        ));
    }

    /**
     * @return array<string, string>
     */
    protected function userAttributes(): array
    {
        return [
            'name' => 'nome',
            'email' => 'e-mail',
            'type' => 'tipo',
            'role' => 'perfil',
            'oab_number' => 'número da OAB',
            'oab_state' => 'seccional da OAB',
            'birth_date' => 'data de nascimento',
            'password' => 'senha',
        ];
    }
}
