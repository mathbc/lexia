<?php

declare(strict_types=1);

namespace App\Domain\Users\Enums;

use App\Domain\Shared\Concerns\ProvidesOptions;
use App\Domain\Shared\Contracts\HasLabel;

/**
 * What a user may do inside their own account.
 *
 * All three roles are account-scoped: none of them grants visibility across
 * tenants. Platform-wide access is a separate flag on the user, deliberately
 * kept out of this enum.
 */
enum UserRole: string implements HasLabel
{
    use ProvidesOptions;

    /** Owns the account: every account setting, plus the other admins. */
    case AccountAdmin = 'account_admin';

    /** Manages the account's users, but not the account itself. */
    case Admin = 'admin';

    /** Ordinary user. */
    case Lawyer = 'lawyer';

    public function label(): string
    {
        return match ($this) {
            self::AccountAdmin => 'Admin da Conta',
            self::Admin => 'Admin',
            self::Lawyer => 'Advogado',
        };
    }

    /**
     * Rank used to compare authority. Higher wins.
     */
    public function level(): int
    {
        return match ($this) {
            self::AccountAdmin => 3,
            self::Admin => 2,
            self::Lawyer => 1,
        };
    }

    /**
     * Whether this role sits strictly above another.
     *
     * The comparison is strict on purpose: peers must not be able to manage
     * each other, otherwise two admins could lock one another out.
     */
    public function outranks(self $other): bool
    {
        return $this->level() > $other->level();
    }

    public function manages(): bool
    {
        return $this !== self::Lawyer;
    }
}
