<?php

declare(strict_types=1);

namespace App\Domain\LegalPrecedents\Actions;

use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalPrecedents\Data\LegalPrecedentData;
use App\Domain\LegalPrecedents\Models\LegalPrecedent;
use App\Domain\LegalTheses\Models\LegalThesis;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Registers one ruling on a pleading, optionally grounding a thesis.
 *
 * The thesis arrives as a **model and not an id**, which is this Action's half
 * of the rule SaveLegalCaseForensicReview states at length: a posted
 * `legal_thesis_id` is a uuid like any other, and the database would accept one
 * belonging to another account's thesis without complaint, because a foreign key
 * checks existence and not ownership. Taking the model moves that decision to
 * the caller, where a route-model binding and a Policy can be applied to it;
 * taking an id would put a hole here that no test in this class could see.
 *
 * `belongsToPleading()` is still checked, because resolving a model correctly
 * and resolving the *right* model are different things: a thesis from a sibling
 * pleading of the same account passes every tenant guard there is.
 *
 * No `asController()` while no route points here.
 */
final class CreateLegalPrecedent
{
    use AsAction;

    public function handle(
        LegalCase $legalCase,
        LegalPrecedentData $data,
        ?LegalThesis $thesis = null,
    ): LegalPrecedent {
        $precedent = new LegalPrecedent([
            'account_id' => $legalCase->account_id,
            'legal_case_id' => $legalCase->id,
        ]);

        $precedent->fill($data->toArray($this->groundedIn($legalCase, $thesis)))->save();

        return $precedent;
    }

    /**
     * The thesis's key, but only if it is a thesis of this pleading.
     */
    private function groundedIn(LegalCase $legalCase, ?LegalThesis $thesis): ?string
    {
        if ($thesis === null || $thesis->legal_case_id !== $legalCase->id) {
            return null;
        }

        return $thesis->id;
    }
}
