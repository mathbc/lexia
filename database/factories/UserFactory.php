<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Accounts\Models\Account;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Enums\UserType;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * Hashing once and reusing it keeps test suites fast; bcrypt is
     * deliberately slow and every factory call would otherwise pay for it.
     */
    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => self::$password ??= Hash::make('password'),
            'oab_number' => (string) fake()->numberBetween(1000, 999999),
            'oab_state' => fake()->randomElement(['SP', 'RJ', 'MG', 'RS', 'PR']),
            'birth_date' => fake()->dateTimeBetween('-70 years', '-22 years'),
            'role' => UserRole::Lawyer,
            'type' => UserType::Lawyer,
            'enabled' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function accountAdmin(): static
    {
        return $this->state(fn (): array => ['role' => UserRole::AccountAdmin]);
    }

    public function admin(): static
    {
        return $this->state(fn (): array => ['role' => UserRole::Admin]);
    }

    public function judge(): static
    {
        return $this->state(fn (): array => [
            'type' => UserType::Judge,
            'oab_number' => null,
            'oab_state' => null,
        ]);
    }

    /**
     * LexIA staff. They always sit in the platform account — the role exists
     * to administer the other tenants, not to belong to one.
     */
    public function platformAdmin(): static
    {
        return $this->state(fn (): array => [
            'account_id' => Account::PLATFORM_ID,
            'role' => UserRole::PlatformAdmin,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['enabled' => false]);
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => ['email_verified_at' => null]);
    }

    /**
     * Attach to an existing account instead of creating a fresh one.
     */
    public function forAccount(Account $account): static
    {
        return $this->state(fn (): array => ['account_id' => $account->id]);
    }
}
