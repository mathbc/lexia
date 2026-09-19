<?php

declare(strict_types=1);

namespace App\Domain\LegalPrecedents\Actions;

use App\Domain\LegalPrecedents\Data\LegalPrecedentData;
use App\Domain\LegalPrecedents\Models\LegalPrecedent;
use App\Domain\LegalTheses\Models\LegalThesis;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Rewrites one ruling, and says again which thesis it grounds.
 *
 * The thesis is a parameter of the call and not a property of the data, so
 * passing none detaches the precedent. That is the honest reading of an update
 * that writes the row whole, and it matches how the step's save behaves: a
 * precedent whose thesis is no longer named comes back grounding nothing.
 *
 * As in CreateLegalPrecedent, the thesis arrives as a model and is accepted only
 * if it belongs to this precedent's own pleading — a foreign key would check
 * that the row exists, which is not the question.
 *
 * No `asController()` while no route points here.
 */
final class UpdateLegalPrecedent
{
    use AsAction;

    public function handle(
        LegalPrecedent $precedent,
        LegalPrecedentData $data,
        ?LegalThesis $thesis = null,
    ): LegalPrecedent {
        $precedent->update($data->toArray($this->groundedIn($precedent, $thesis)));

        return $precedent->refresh();
    }

    private function groundedIn(LegalPrecedent $precedent, ?LegalThesis $thesis): ?string
    {
        if ($thesis === null || $thesis->legal_case_id !== $precedent->legal_case_id) {
            return null;
        }

        return $thesis->id;
    }
}
