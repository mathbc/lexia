<?php

declare(strict_types=1);

namespace App\Domain\Users\Models;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Accounts\Models\Account;
use App\Domain\Shared\Concerns\BelongsToAccount;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Enums\UserType;
use App\Domain\Users\Policies\UserPolicy;
use Carbon\CarbonImmutable;
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
 * @property string $id
 * @property string $account_id
 * @property string $name
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property string|null $oab_number
 * @property BrazilianState|null $oab_state
 * @property CarbonImmutable|null $birth_date
 * @property UserRole $role
 * @property UserType $type
 * @property bool $enabled
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
     * Mirrors the schema default so a freshly built model behaves the same
     * before and after it is persisted.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'enabled' => true,
    ];

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
            // O docblock acima promete o enum desde sempre; o cast faltava, e
            // quem escrevesse `$user->oab_state->value` — como o timbre da
            // minuta escreve — recebia uma string e quebrava. `Account` já o tem.
            'oab_state' => BrazilianState::class,
            'role' => UserRole::class,
            'type' => UserType::class,
            'enabled' => 'boolean',
        ];
    }

    /**
     * OAB enrolment as practitioners write it, e.g. "OAB/SP 123456".
     *
     * The twin of `Account::oabRegistration()`, and both are kept rather than
     * shared because they answer different questions: the account's is the firm
     * or the sole practitioner the tenant *is*, and this one is the lawyer who
     * signs. For an `individual` account they are usually the same person, and
     * on a pleading it is this one that goes under the signature.
     *
     * Null when either half is missing, which is a real state: the number and
     * the seccional are nullable, a judge legitimately has neither, and a lawyer
     * who has not filled them in yet gets a gap in the letterhead rather than a
     * half-written enrolment.
     */
    public function oabRegistration(): ?string
    {
        if ($this->oab_number === null || $this->oab_state === null) {
            return null;
        }

        return "OAB/{$this->oab_state->value} {$this->oab_number}";
    }

    /**
     * LexIA staff: the one role that is not confined to a single account.
     */
    public function isPlatformAdmin(): bool
    {
        return $this->role === UserRole::PlatformAdmin;
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
