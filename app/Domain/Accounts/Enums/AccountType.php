<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Enums;

use App\Domain\Shared\Concerns\ProvidesOptions;
use App\Domain\Shared\Contracts\HasLabel;

/**
 * What kind of legal entity owns the account.
 *
 * Drives which identification fields are required: an individual practitioner
 * is identified by their OAB enrolment, a firm by its CNPJ.
 */
enum AccountType: string implements HasLabel
{
    use ProvidesOptions;

    case Individual = 'individual';
    case LawFirm = 'law_firm';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Pessoa Física — OAB',
            self::LawFirm => 'Pessoa Jurídica',
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
}
