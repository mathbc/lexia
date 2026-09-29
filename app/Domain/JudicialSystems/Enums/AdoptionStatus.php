<?php

declare(strict_types=1);

namespace App\Domain\JudicialSystems\Enums;

use App\Domain\Shared\Concerns\ProvidesOptions;
use App\Domain\Shared\Contracts\HasLabel;

/**
 * Where a state court stands with one electronic system.
 *
 * Only `Active` is a plain "files here"; the other three are the court moving
 * between systems, which is most of what the map records at the moment — the
 * eproc is spreading, and a court in transition still takes filings through
 * whatever it is leaving. Kept apart from the system itself because the same
 * eproc is in use in one court and being rolled out in the next.
 */
enum AdoptionStatus: string implements HasLabel
{
    use ProvidesOptions;

    case Active = 'active';
    case Transition = 'transition';
    case Implementation = 'implementation';
    case Coexistence = 'coexistence';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Em uso',
            self::Transition => 'Em transição',
            self::Implementation => 'Em implantação',
            self::Coexistence => 'Em coexistência com sistemas anteriores',
        };
    }
}
