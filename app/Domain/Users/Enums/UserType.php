<?php

declare(strict_types=1);

namespace App\Domain\Users\Enums;

use App\Domain\Shared\Concerns\ProvidesOptions;
use App\Domain\Shared\Contracts\HasLabel;

/**
 * The user's professional standing.
 *
 * Informational only for now — it carries no permissions. It exists because
 * the Jurisprudence module will phrase prompts differently for a judge than
 * for a practising lawyer.
 */
enum UserType: string implements HasLabel
{
    use ProvidesOptions;

    case Lawyer = 'lawyer';
    case Judge = 'judge';
    case AppellateJudge = 'appellate_judge';

    public function label(): string
    {
        return match ($this) {
            self::Lawyer => 'Advogado',
            self::Judge => 'Juiz',
            self::AppellateJudge => 'Desembargador',
        };
    }
}
