<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Customers\Actions\CreateCustomer;
use App\Domain\Customers\Enums\CustomerType;
use App\Domain\Customers\Enums\MaritalStatus;
use App\Domain\Customers\Models\Customer;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalCases\Support\LegalCaseFormProps;
use App\Domain\LegalCases\Support\LegalCaseOptions;
use App\Domain\LegalPrecedents\Enums\LegalPrecedentType;
use App\Domain\LegalTheses\Enums\LegalThesisType;
use App\Domain\ProceduralClasses\Enums\JurisdictionDegree;
use App\Domain\ProceduralClasses\Enums\JusticeBranch;
use App\Domain\ProceduralClasses\Queries\ProceduralClassOptionsQuery;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The six-step form for drafting a pleading — opening a new one, and reopening
 * one already saved.
 *
 * Both routes land here, as they do for users on ShowUserForm, and both render
 * the same page: the two screens differ by one prop, not by their shape. The
 * pleading being absent is what says "new".
 *
 * `initialStep` is where the browser opens, and is deliberately not the same
 * thing as `current_step`. The latter is a high-water mark — the furthest step
 * the pleading ever reached — while the former is where this particular visit
 * begins: the `?etapa` a save just redirected to, or, when someone arrives from
 * the listing with no query, the high-water mark itself. Keeping them apart is
 * what lets a lawyer re-save step 1 without the pleading appearing to retreat.
 *
 * `proceduralClasses` answers the `area` query parameter, so the initial
 * payload carries none of the 615 classes and a partial reload fetches the
 * chosen area's list. On a saved pleading the area falls back to the one
 * already chosen — otherwise reopening a draft would show an empty class list
 * under a class that is plainly selected.
 *
 * The client select also registers one: `customerTypes`, `maritalStatuses` and
 * `states` feed the dialog it opens, and `createdCustomer` is how the client that was just saved
 * finds its way back — CreateCustomer flashes it and redirects here, so the
 * select can show it chosen without a second round trip.
 *
 * `thesisTypes` and `precedentTypes` are the sixth step's share of the same
 * rule: the forensic review arrives from the classification carrying the enum's
 * backing value, because that is what will be written, and the Portuguese label
 * is resolved here rather than restated in TypeScript.
 */
final class ShowLegalCaseForm
{
    use AsAction;

    public function __construct(private readonly ProceduralClassOptionsQuery $classes) {}

    public function authorize(ActionRequest $request): bool
    {
        $legalCase = $request->route('legalCase');

        return $legalCase instanceof LegalCase
            ? $request->user()->can('update', $legalCase)
            : $request->user()->can('create', LegalCase::class);
    }

    public function asController(ActionRequest $request, ?LegalCase $legalCase = null): Response
    {
        $area = $this->area($request, $legalCase);

        return Inertia::render('legal-cases/form', [
            'legalCase' => $legalCase instanceof LegalCase
                ? LegalCaseFormProps::draft($legalCase)
                : null,
            'initialStep' => $this->initialStep($request, $legalCase)->value,
            'steps' => LegalCaseStep::options(),
            'customers' => LegalCaseOptions::customers($request->user()->account_id),
            'practiceAreas' => LegalCaseOptions::practiceAreas(),
            'proceduralClasses' => $this->classes->forArea($area),
            'selectedArea' => $area,
            'branches' => JusticeBranch::options(),
            'degrees' => JurisdictionDegree::options(),
            // A revisão forense recebe teses e precedentes com o `type` no valor
            // do enum, porque é ele que será gravado; o português dos rótulos
            // continua morando de um lado só, e é daqui que a tela o lê.
            'thesisTypes' => LegalThesisType::options(),
            'precedentTypes' => LegalPrecedentType::options(),
            'customerTypes' => CustomerType::options(),
            'maritalStatuses' => MaritalStatus::options(),
            'states' => BrazilianState::options(),
            'can' => [
                'create_customer' => $request->user()->can('create', Customer::class),
            ],
            'createdCustomer' => $request->session()->get(CreateCustomer::INLINE_FLASH_KEY),
        ]);
    }

    /**
     * The query string wins, because that is what a save redirects with and
     * what the timeline navigates by; the saved pleading is the fallback.
     */
    private function area(ActionRequest $request, ?LegalCase $legalCase): string
    {
        $area = $request->string('area')->toString();

        return $area !== '' ? $area : ($legalCase?->practiceArea->slug ?? '');
    }

    /**
     * Where to open: the step the URL names, but never past the high-water mark.
     *
     * The `?etapa` used to be taken at face value, and that was a hole with two
     * ends. `GET /pecas/{id}/editar?etapa=review` opened step 6 on a pleading
     * whose `current_step` was `defendant`, showing a forensic review for a
     * matter with no facts and no requests — and, now that opening that step
     * fires the research, it would have spent a cloud inference on a dossier
     * that says nothing. On a pleading that does not exist yet there is no
     * `etapa` that means anything at all, because there is no row for any step
     * to have been reached on.
     *
     * So the query parameter can only ever narrow: it picks a step the pleading
     * has genuinely been to, and anything beyond that lands on the mark itself.
     * A new pleading always opens at the beginning.
     *
     * This does not make `initialStep` the same thing as `current_step`, and
     * the distinction the class docblock draws still holds: the mark is the
     * furthest the pleading ever got, while this is where *this* visit begins.
     * Re-saving step 1 still redirects to `?etapa=defendant` and still opens
     * there, on a pleading whose mark stays at step 6.
     */
    private function initialStep(ActionRequest $request, ?LegalCase $legalCase): LegalCaseStep
    {
        if (! $legalCase instanceof LegalCase) {
            return LegalCaseStep::Basics;
        }

        $furthest = $legalCase->current_step;
        $named = LegalCaseStep::tryFrom($request->string('etapa')->toString());

        return $named instanceof LegalCaseStep && $named->position() <= $furthest->position()
            ? $named
            : $furthest;
    }
}
