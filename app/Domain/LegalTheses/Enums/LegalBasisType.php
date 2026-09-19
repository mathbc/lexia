<?php

declare(strict_types=1);

namespace App\Domain\LegalTheses\Enums;

use App\Domain\Shared\Concerns\ProvidesOptions;
use App\Domain\Shared\Contracts\HasLabel;

/**
 * What kind of authority one item of a thesis's fundamentação is.
 *
 * Five instruments, because that is what the chips under a thesis actually say:
 * "Art. 135, III, do CTN", "Lei nº 6.830/80", "Súmula 393 do STJ",
 * "ADC 49 do STF (Tema 1099)", "Tema 981 do STJ".
 *
 * `Statute` exists next to `Article` because a whole statute is cited as
 * itself — the Lei de Execuções Fiscais is invoked entire, not by an article —
 * and without the case it would arrive as an Article with no article in it.
 *
 * `Sumula` and `Adc` keep their Portuguese spelling, for the reason
 * LegalPrecedentType::Sumula gives: they are the proper names of Brazilian
 * instruments rather than concepts with English equivalents. An ADC is the ação
 * declaratória de constitucionalidade, and nobody — including a court — calls it
 * anything else. ADI and ADPF join as cases when a thesis cites one; that is a
 * line here and nothing else.
 *
 * This enum is deliberately **not** merged with LegalPrecedentType, although
 * both have a Sumula: they answer different questions, and one enum would make
 * a precedent of kind "Article" representable, which is not a thing.
 */
enum LegalBasisType: string implements HasLabel
{
    use ProvidesOptions;

    case Article = 'article';
    case Statute = 'statute';
    case Sumula = 'sumula';
    case Adc = 'adc';
    case Theme = 'theme';

    public function label(): string
    {
        return match ($this) {
            self::Article => 'Dispositivo de lei',
            self::Statute => 'Lei',
            self::Sumula => 'Súmula',
            self::Adc => 'ADC',
            self::Theme => 'Tema',
        };
    }
}
