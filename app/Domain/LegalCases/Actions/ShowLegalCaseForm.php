<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalCases\Support\LegalCaseOptions;
use App\Domain\ProceduralClasses\Enums\JurisdictionDegree;
use App\Domain\ProceduralClasses\Enums\JusticeBranch;
use App\Domain\ProceduralClasses\Queries\ProceduralClassOptionsQuery;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The three-step form for drafting a pleading.
 *
 * Read-only for now: step 1 gathers the client, the area and the class, and
 * nothing is persisted — there is no CreateLegalCase yet. When it lands, the
 * pairing of area and class is this flow's to validate, because the database
 * cannot: the two foreign keys are independent and the valid pairs live in the
 * pivot.
 *
 * `proceduralClasses` answers the `area` query parameter, so the initial
 * payload carries none of the 615 classes and a partial reload fetches the
 * chosen area's list.
 */
final class ShowLegalCaseForm
{
    use AsAction;

    public function __construct(private readonly ProceduralClassOptionsQuery $classes) {}

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('create', LegalCase::class);
    }

    public function asController(ActionRequest $request): Response
    {
        $area = $request->string('area')->toString();

        return Inertia::render('legal-cases/create', [
            'customers' => LegalCaseOptions::customers($request->user()->account_id),
            'practiceAreas' => LegalCaseOptions::practiceAreas(),
            'proceduralClasses' => $this->classes->forArea($area),
            'selectedArea' => $area,
            'branches' => JusticeBranch::options(),
            'degrees' => JurisdictionDegree::options(),
        ]);
    }
}
