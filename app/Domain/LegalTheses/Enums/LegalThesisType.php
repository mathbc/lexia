<?php

declare(strict_types=1);

namespace App\Domain\LegalTheses\Enums;

use App\Domain\Shared\Concerns\ProvidesOptions;
use App\Domain\Shared\Contracts\HasLabel;

/**
 * What kind of argument a thesis is, and therefore where it sits in the peça.
 *
 * The order is the order a pleading makes them: what must be decided before the
 * merits, the merits themselves, the reading that applies if the main one
 * fails, and what is asked on top of either.
 *
 * `SubsidiaryMerits` is the *mérito subsidiário* — pleading in the alternative,
 * "e se assim não se entender, então…". It is not a weaker version of the main
 * thesis; it is the one that survives if the main one is rejected, and losing
 * the distinction would let a pleading argue two incompatible things as if both
 * were its position.
 */
enum LegalThesisType: string implements HasLabel
{
    use ProvidesOptions;

    case Preliminary = 'preliminary';
    case PrincipalMerits = 'principal_merits';
    case SubsidiaryMerits = 'subsidiary_merits';
    case AncillaryRequest = 'ancillary_request';

    public function label(): string
    {
        return match ($this) {
            self::Preliminary => 'Preliminar',
            self::PrincipalMerits => 'Mérito principal',
            self::SubsidiaryMerits => 'Mérito subsidiário',
            self::AncillaryRequest => 'Pedido acessório',
        };
    }
}
