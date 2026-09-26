<?php

declare(strict_types=1);

namespace App\Domain\LegalThemes\Enums;

use App\Domain\Shared\Concerns\ProvidesOptions;
use App\Domain\Shared\Contracts\HasLabel;
use UnexpectedValueException;

/**
 * Which kind of qualified precedent a row of the STJ file is.
 *
 * Only `Theme` binds: it is the repetitive appeal whose thesis every court must
 * follow. A `Controversy` is the step before — a question proposed to become a
 * tema, which the file keeps after it is linked, cancelled or absorbed, so
 * reading its `status` matters more than reading its question. PUIL, IAC and
 * SIRDR are the other instruments the STJ's precedent office tracks in the same
 * table.
 */
enum LegalThemeType: string implements HasLabel
{
    use ProvidesOptions;

    case Theme = 'theme';
    case Controversy = 'controversy';
    case Puil = 'puil';
    case Iac = 'iac';
    case Sirdr = 'sirdr';

    public function label(): string
    {
        return match ($this) {
            self::Theme => 'Tema Repetitivo',
            self::Controversy => 'Controvérsia',
            self::Puil => 'Pedido de Uniformização de Interpretação de Lei',
            self::Iac => 'Incidente de Assunção de Competência',
            self::Sirdr => 'Suspensão em Incidente de Resolução de Demandas Repetitivas',
        };
    }

    /**
     * The case for the STJ's `tipoPrecedente`.
     *
     * An unknown value throws instead of falling back: a type the code does not
     * know is a new instrument nobody has labelled, and importing it under a
     * guess would file it next to precedents it has nothing to do with.
     */
    public static function fromSource(string $raw): self
    {
        return match (trim($raw)) {
            'Tema' => self::Theme,
            'Controvérsia' => self::Controversy,
            'PUIL' => self::Puil,
            'IAC' => self::Iac,
            'SIRDR' => self::Sirdr,
            default => throw new UnexpectedValueException("Tipo de precedente desconhecido: [{$raw}]."),
        };
    }
}
