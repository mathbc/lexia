<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Enums;

use App\Domain\Shared\Concerns\ProvidesOptions;
use App\Domain\Shared\Contracts\HasLabel;

/**
 * What the screening made of a narrative before the smart fill spends anything
 * on it.
 *
 * One way in and four ways out, and the four are kept apart because each one
 * asks the lawyer for something different: a text that reads as noise is
 * rewritten, a coherent text about nothing legal is replaced, a vague one is
 * completed, and one that talks to the system instead of about the client is
 * not a narrative at all.
 *
 * The agent never names a verdict. It answers the signals, and
 * FactsScreeningData derives the verdict from them — so these values are not a
 * wire format, and the label is the headline of the refusal the screen shows.
 */
enum FactsScreeningVerdict: string implements HasLabel
{
    use ProvidesOptions;

    case Admissible = 'admissible';
    case Unintelligible = 'unintelligible';
    case NotLegal = 'not_legal';
    case Vague = 'vague';
    case Manipulation = 'manipulation';

    public function label(): string
    {
        return match ($this) {
            self::Admissible => 'O relato descreve um caso jurídico.',
            self::Unintelligible => 'O texto não forma um relato compreensível.',
            self::NotLegal => 'O texto não descreve uma situação que possa virar uma peça jurídica.',
            self::Vague => 'O relato é vago demais para montar uma peça.',
            self::Manipulation => 'O texto traz instruções ao sistema em vez dos fatos do caso.',
        };
    }
}
