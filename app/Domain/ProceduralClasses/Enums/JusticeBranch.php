<?php

declare(strict_types=1);

namespace App\Domain\ProceduralClasses\Enums;

use App\Domain\Shared\Concerns\ProvidesOptions;
use App\Domain\Shared\Contracts\HasLabel;

/**
 * The branch of the Judiciary a CNJ jurisdiction belongs to.
 *
 * One half of how the 29 raw competences are made choosable: a lawyer picks a
 * branch and a degree, never `just_es_juizado_es_fp`. The mapping from the raw
 * value lives in Jurisdiction, which is the only place that knows the codes.
 */
enum JusticeBranch: string implements HasLabel
{
    use ProvidesOptions;

    case State = 'state';
    case Federal = 'federal';
    case Labor = 'labor';
    case Electoral = 'electoral';
    case Military = 'military';
    case Superior = 'superior';

    public function label(): string
    {
        return match ($this) {
            self::State => 'Justiça Estadual',
            self::Federal => 'Justiça Federal',
            self::Labor => 'Justiça do Trabalho',
            self::Electoral => 'Justiça Eleitoral',
            self::Military => 'Justiça Militar',
            self::Superior => 'Tribunais superiores e conselhos',
        };
    }
}
