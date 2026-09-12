<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Enums;

use App\Domain\Shared\Concerns\ProvidesOptions;
use App\Domain\Shared\Contracts\HasLabel;

/**
 * What kind of legal entity owns the account.
 *
 * Drives which identification fields are required: an individual practitioner
 * is identified by their OAB enrolment, a firm by its CNPJ. Platform is the
 * odd one out — it identifies LexIA's own account and carries no document.
 */
enum AccountType: string implements HasLabel
{
    use ProvidesOptions;

    case Individual = 'individual';
    case LawFirm = 'law_firm';

    /**
     * LexIA itself. Exactly one account has this type — the one created by
     * migration — and it is where the platform admins live.
     */
    case Platform = 'platform';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Pessoa Física — OAB',
            self::LawFirm => 'Pessoa Jurídica',
            self::Platform => 'Plataforma LexIA',
        };
    }

    public function requiresOab(): bool
    {
        return $this === self::Individual;
    }

    public function requiresFederalId(): bool
    {
        return $this === self::LawFirm;
    }

    /**
     * A firm trades under a registered corporate name; an individual does not.
     */
    public function requiresLegalName(): bool
    {
        return $this === self::LawFirm;
    }

    /**
     * The types a customer may have.
     *
     * Everywhere a type is chosen — sign-up, the account form — offers these
     * and only these, so the platform type can never be reached from the UI.
     *
     * @return list<self>
     */
    public static function customerCases(): array
    {
        return [self::Individual, self::LawFirm];
    }

    /**
     * @return list<string>
     */
    public static function customerValues(): array
    {
        return array_map(
            static fn (self $type): string => $type->value,
            self::customerCases(),
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function customerOptions(): array
    {
        return self::optionsFrom(self::customerCases());
    }
}
