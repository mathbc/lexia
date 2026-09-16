<?php

declare(strict_types=1);

namespace App\Domain\ProceduralClasses\Enums;

use App\Domain\Shared\Concerns\ProvidesOptions;
use App\Domain\Shared\Contracts\HasLabel;

/**
 * The level at which a CNJ jurisdiction sits.
 *
 * The other half of the branch × degree pair. Kept apart from JusticeBranch
 * because the two combine: "Federal" and "1º grau" together name exactly one
 * competence, and either alone names several.
 */
enum JurisdictionDegree: string implements HasLabel
{
    use ProvidesOptions;

    case First = 'first';
    case Second = 'second';
    case SmallClaims = 'small_claims';
    case Panels = 'panels';
    case Superior = 'superior';
    case Council = 'council';

    public function label(): string
    {
        return match ($this) {
            self::First => '1º grau',
            self::Second => '2º grau',
            self::SmallClaims => 'Juizado especial',
            self::Panels => 'Turmas recursais e de uniformização',
            self::Superior => 'Tribunal superior',
            self::Council => 'Conselho',
        };
    }
}
