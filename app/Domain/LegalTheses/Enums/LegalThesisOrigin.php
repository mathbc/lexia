<?php

declare(strict_types=1);

namespace App\Domain\LegalTheses\Enums;

use App\Domain\Shared\Concerns\ProvidesOptions;
use App\Domain\Shared\Contracts\HasLabel;

/**
 * Who wrote a thesis: the research agents, or the lawyer by hand.
 *
 * Two consequences hang on it, and both are about ownership. A manual thesis is
 * the lawyer's text, so it is the only kind the screen lets them edit — a
 * researched one is a reading of an official portal, and rewriting it would
 * leave a citation that no longer says what the portal said. And a manual thesis
 * survives "Pesquisar novamente": the research reconciles what *it* found, and
 * has no business deleting what the lawyer wrote because the portals did not
 * find it too.
 *
 * Only CreateLegalThesis writes `Manual`. Every other path — the research, the
 * "Concluir" — leaves the column alone, because `LegalThesisData::toArray()`
 * does not carry it and a posted payload therefore cannot move it.
 */
enum LegalThesisOrigin: string implements HasLabel
{
    use ProvidesOptions;

    case Ai = 'ai';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Ai => 'IA',
            self::Manual => 'Manual',
        };
    }
}
