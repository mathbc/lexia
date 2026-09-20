<?php

declare(strict_types=1);

namespace App\Domain\Customers\Enums;

use App\Domain\Shared\Concerns\ProvidesOptions;
use App\Domain\Shared\Contracts\HasLabel;

/**
 * The civil status a petição inicial states when it qualifies a party.
 *
 * A closed list rather than free text because the qualification paragraph is
 * copied into a document a judge reads: "casado" and "Casado" and "CASADO(A)"
 * are the same fact typed three ways, and only one of them can be the one the
 * draft carries.
 *
 * The labels keep the "(a)" of the Brazilian form, which is the honest form
 * for a select: the registration knows the client's civil status, not their
 * gender, and agreeing the word is the drafting agent's job — it already
 * agrees "brasileiro"/"brasileira" the same way.
 *
 * Only a natural person has one; CustomerData clears it when the client is a
 * company, the same rule the CPF follows.
 */
enum MaritalStatus: string implements HasLabel
{
    use ProvidesOptions;

    case Single = 'single';
    case Married = 'married';
    case StableUnion = 'stable_union';
    case Separated = 'separated';
    case Divorced = 'divorced';
    case Widowed = 'widowed';

    public function label(): string
    {
        return match ($this) {
            self::Single => 'Solteiro(a)',
            self::Married => 'Casado(a)',
            self::StableUnion => 'Em união estável',
            self::Separated => 'Separado(a) judicialmente',
            self::Divorced => 'Divorciado(a)',
            self::Widowed => 'Viúvo(a)',
        };
    }
}
