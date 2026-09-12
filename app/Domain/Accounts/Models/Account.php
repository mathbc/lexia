<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Models;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Accounts\Policies\AccountPolicy;
use App\Domain\Accounts\Scopes\VisibleAccountScope;
use App\Domain\Users\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tenant: either an individual practitioner or a law firm.
 *
 * @property string $id
 * @property string $name
 * @property string|null $legal_name
 * @property AccountType $type
 * @property string|null $federal_id
 * @property string|null $oab_number
 * @property BrazilianState|null $oab_state
 * @property string $email
 * @property string $phone
 * @property string $postal_code
 * @property string $street
 * @property string $number
 * @property string|null $complement
 * @property string $district
 * @property string $city
 * @property BrazilianState $state
 * @property bool $active
 * @property bool $enabled
 * @property CarbonImmutable|null $deleted_at
 * @property-read Collection<int, User> $users
 */
#[ScopedBy(VisibleAccountScope::class)]
#[UsePolicy(AccountPolicy::class)]
#[UseFactory(AccountFactory::class)]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'oab_state' => BrazilianState::class,
            'state' => BrazilianState::class,
            'active' => 'boolean',
            'enabled' => 'boolean',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Whether the account may be used at all.
     *
     * Both switches must be on: `enabled` is LexIA's (contract, billing) and
     * `active` is the customer's own.
     */
    public function isOperational(): bool
    {
        return $this->active && $this->enabled;
    }

    /**
     * The identifier that legally distinguishes this account.
     */
    public function identifier(): ?string
    {
        return $this->type->requiresFederalId()
            ? $this->federal_id
            : $this->oabRegistration();
    }

    /**
     * OAB enrolment as practitioners write it, e.g. "OAB/SP 123456".
     */
    public function oabRegistration(): ?string
    {
        if ($this->oab_number === null || $this->oab_state === null) {
            return null;
        }

        return "OAB/{$this->oab_state->value} {$this->oab_number}";
    }

    /**
     * What to show as the account's name: firms trade under a corporate name,
     * individuals under their own.
     */
    public function displayName(): string
    {
        return $this->legal_name ?? $this->name;
    }
}
