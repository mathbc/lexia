<?php

declare(strict_types=1);

namespace App\Domain\Users\Models;

use App\Domain\Accounts\Models\Account;
use App\Domain\Shared\Concerns\BelongsToAccount;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Enums\UserType;
use App\Domain\Users\Policies\UserPolicy;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A member of exactly one account.
 *
 * @property-read Account $account
 */
#[UsePolicy(UserPolicy::class)]
#[UseFactory(UserFactory::class)]
class User extends Authenticatable implements MustVerifyEmail
{
    use BelongsToAccount;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuids;
    use Notifiable;
    use SoftDeletes;

    protected $guarded = ['id'];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'birth_date' => 'date',
            'role' => UserRole::class,
            'type' => UserType::class,
            'enabled' => 'boolean',
            'platform_admin' => 'boolean',
        ];
    }

    /**
     * LexIA staff. Deliberately not a UserRole: the three business roles are
     * all account-scoped, and this one exists to cross that boundary.
     */
    public function isPlatformAdmin(): bool
    {
        return $this->platform_admin;
    }

    /**
     * Whether this user may act on another.
     *
     * Two guards, both necessary: never across accounts, and never on a peer or
     * a superior — otherwise two admins could lock each other out.
     */
    public function canManage(self $other): bool
    {
        if ($this->isPlatformAdmin()) {
            return true;
        }

        if ($this->account_id !== $other->account_id) {
            return false;
        }

        return $this->role->outranks($other->role);
    }

    /**
     * Whether the user can sign in and use the product right now.
     */
    public function canAccessPlatform(): bool
    {
        return $this->enabled && $this->account->isOperational();
    }
}
