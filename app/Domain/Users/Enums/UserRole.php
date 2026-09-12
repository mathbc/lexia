<?php

declare(strict_types=1);

namespace App\Domain\Users\Enums;

use App\Domain\Shared\Concerns\ProvidesOptions;
use App\Domain\Shared\Contracts\HasLabel;

/**
 * What a user may do.
 *
 * The bottom three roles are account-scoped: none of them grants visibility
 * across tenants. PlatformAdmin is the exception and sits above all of them —
 * it belongs to LexIA's own account and administers every other one.
 */
enum UserRole: string implements HasLabel
{
    use ProvidesOptions;

    /** LexIA staff: every account, and every user inside them. */
    case PlatformAdmin = 'platform_admin';

    /** Owns the account: every account setting, plus the other admins. */
    case AccountAdmin = 'account_admin';

    /** Manages the account's users, but not the account itself. */
    case Admin = 'admin';

    /** Ordinary user. */
    case Lawyer = 'lawyer';

    public function label(): string
    {
        return match ($this) {
            self::PlatformAdmin => 'Admin do Sistema',
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
            self::PlatformAdmin => 4,
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

    /**
     * The roles that live inside a customer account.
     *
     * PlatformAdmin is excluded: it is not a role a tenant hands out, so it
     * has no business in a customer's filters or dropdowns.
     *
     * @return list<self>
     */
    public static function accountCases(): array
    {
        return [self::AccountAdmin, self::Admin, self::Lawyer];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function accountOptions(): array
    {
        return self::optionsFrom(self::accountCases());
    }
}
