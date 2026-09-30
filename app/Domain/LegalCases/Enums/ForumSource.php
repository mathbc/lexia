<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Enums;

use App\Domain\Shared\Concerns\ProvidesOptions;
use App\Domain\Shared\Contracts\HasLabel;

/**
 * Where the city of the competent forum comes from.
 *
 * The addressing agent picks one of these instead of writing the city down
 * whenever it can, because two of the three are already on record: the
 * client's domicile is registered, and the defendant's address is what the
 * second step saves. For those the city is **copied** from the record by
 * ForumPlace, and whatever the model wrote beside it is ignored — the comarca
 * of a filed document is not something a model gets to spell.
 *
 * `Facts` is the rest: the property's location (CPC, art. 47), the place of
 * the act (art. 53, IV), the place of work (CLT, art. 651), an elected forum
 * (art. 63). Only there does the agent's city count, and only if the narrative
 * writes it.
 */
enum ForumSource: string implements HasLabel
{
    use ProvidesOptions;

    case PlaintiffAddress = 'plaintiff_address';
    case DefendantAddress = 'defendant_address';
    case Facts = 'facts';

    public function label(): string
    {
        return match ($this) {
            self::PlaintiffAddress => 'Domicílio do cliente',
            self::DefendantAddress => 'Endereço do réu',
            self::Facts => 'Lugar citado no relato',
        };
    }
}
