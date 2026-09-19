<?php

declare(strict_types=1);

namespace App\Domain\LegalPrecedents\Enums;

use App\Domain\Shared\Concerns\ProvidesOptions;
use App\Domain\Shared\Contracts\HasLabel;

/**
 * What kind of ruling a precedent is.
 *
 * `Sumula` keeps its Portuguese spelling, against the rule that identifiers are
 * English, and for the reason the rule already admits elsewhere: a súmula is the
 * proper name of a Brazilian instrument and not a concept with an English
 * equivalent. "Summary" means a résumé; the closest honest translations — a
 * consolidated holding, a binding statement of settled case law — are
 * descriptions, not names. `Cpf`, `Cnpj` and `OabNumber` in Shared\Rules take
 * the same licence.
 *
 * `RepetitiveAppeal` does translate — the recurso repetitivo is the STJ's
 * mechanism for deciding one appeal and binding the thousands like it — so it
 * is spelled in English, as the rule asks.
 */
enum LegalPrecedentType: string implements HasLabel
{
    use ProvidesOptions;

    case Sumula = 'sumula';
    case RepetitiveAppeal = 'repetitive_appeal';

    public function label(): string
    {
        return match ($this) {
            self::Sumula => 'Súmula',
            self::RepetitiveAppeal => 'Recurso repetitivo',
        };
    }
}
