<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Enums;

use App\Domain\Shared\Concerns\ProvidesOptions;
use App\Domain\Shared\Contracts\HasLabel;

/**
 * The two species of *tutela de urgência*, which share their requirements and
 * differ in what the measure does to the final request.
 *
 * `Anticipatory` hands over now, provisionally, the very effect the final
 * request asks for — the name taken off the credit register, the surgery paid
 * for. `Precautionary` hands over nothing: it keeps the final request possible
 * — the assets frozen so the damages can still be paid. The difference is not
 * academic: the irreversibility bar of art. 300, §3º, of the CPC applies to the
 * first one only, and the petição argues reversibility only when it asks for it.
 *
 * The values are what InjunctiveReliefSuggestionAgent returns in `kind`, so
 * they are English like every other enum value; the Portuguese is the label.
 */
enum InjunctiveReliefKind: string implements HasLabel
{
    use ProvidesOptions;

    case Anticipatory = 'anticipatory';
    case Precautionary = 'precautionary';

    public function label(): string
    {
        return match ($this) {
            self::Anticipatory => 'Antecipada',
            self::Precautionary => 'Cautelar',
        };
    }
}
