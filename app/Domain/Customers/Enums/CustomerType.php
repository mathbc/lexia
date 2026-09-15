<?php

declare(strict_types=1);

namespace App\Domain\Customers\Enums;

use App\Domain\Shared\Concerns\ProvidesOptions;
use App\Domain\Shared\Contracts\HasLabel;

/**
 * What kind of legal entity the client is.
 *
 * Drives which document identifies them: a natural person by their CPF, a
 * company by its CNPJ. Exactly one side is ever filled in — the same shape
 * AccountType uses for the tenant itself.
 */
enum CustomerType: string implements HasLabel
{
    use ProvidesOptions;

    case Individual = 'individual';
    case Company = 'company';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Pessoa Física',
            self::Company => 'Pessoa Jurídica',
        };
    }

    public function requiresCpf(): bool
    {
        return $this === self::Individual;
    }

    public function requiresCnpj(): bool
    {
        return $this === self::Company;
    }

    /**
     * A company trades under a registered corporate name; a person does not.
     */
    public function requiresLegalName(): bool
    {
        return $this === self::Company;
    }
}
