<?php

declare(strict_types=1);

namespace App\Domain\LegalTheses\Actions;

use App\Domain\LegalTheses\Data\LegalThesisData;
use App\Domain\LegalTheses\Models\LegalThesis;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Rewrites one thesis.
 *
 * Takes the model and not an id, which is what makes it safe to call without an
 * actor: whoever resolved the model has already decided it is the right one, and
 * there is no id here to be mistaken for a key. `LegalThesisData::toArray()`
 * omits `id` anyway, so neither the key nor the two ownership columns can be
 * moved by an update.
 *
 * No `asController()` while no route points here.
 */
final class UpdateLegalThesis
{
    use AsAction;

    public function handle(LegalThesis $thesis, LegalThesisData $data): LegalThesis
    {
        $thesis->update($data->toArray());

        return $thesis->refresh();
    }
}
