<?php

declare(strict_types=1);

namespace App\Domain\LegalThemes\Enums;

use App\Domain\Shared\Concerns\ProvidesOptions;
use App\Domain\Shared\Contracts\HasLabel;
use UnexpectedValueException;

/**
 * The STJ body that judges a precedent — one of three sections or the Special
 * Court.
 *
 * It is also a proxy for the subject: the First Section judges public and
 * tax law, the Second private law, the Third criminal law, and the Special
 * Court whatever crosses sections.
 */
enum JudgingBody: string implements HasLabel
{
    use ProvidesOptions;

    case FirstSection = 'first_section';
    case SecondSection = 'second_section';
    case ThirdSection = 'third_section';
    case SpecialCourt = 'special_court';

    public function label(): string
    {
        return match ($this) {
            self::FirstSection => 'Primeira Seção',
            self::SecondSection => 'Segunda Seção',
            self::ThirdSection => 'Terceira Seção',
            self::SpecialCourt => 'Corte Especial',
        };
    }

    /**
     * The case for the STJ's `orgaoJulgador`, or null when the file leaves it
     * blank — which it does for a handful of rows. An unknown code throws, for
     * the reason LegalThemeType::fromSource() gives.
     */
    public static function fromSource(string $raw): ?self
    {
        return match (trim($raw)) {
            '' => null,
            'S1' => self::FirstSection,
            'S2' => self::SecondSection,
            'S3' => self::ThirdSection,
            'CE' => self::SpecialCourt,
            default => throw new UnexpectedValueException("Órgão julgador desconhecido: [{$raw}]."),
        };
    }
}
