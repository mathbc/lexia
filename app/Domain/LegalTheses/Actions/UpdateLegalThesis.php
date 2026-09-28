<?php

declare(strict_types=1);

namespace App\Domain\LegalTheses\Actions;

use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalTheses\Actions\Concerns\ValidatesLegalThesis;
use App\Domain\LegalTheses\Data\LegalThesisData;
use App\Domain\LegalTheses\Models\LegalThesis;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Rewrites one thesis — the "Editar" of a thesis the lawyer wrote by hand.
 *
 * Takes the model and not an id, which is what makes it safe to call without an
 * actor: whoever resolved the model has already decided it is the right one, and
 * there is no id here to be mistaken for a key. `LegalThesisData::toArray()`
 * omits `id` and `origin`, so neither the key, the two ownership columns nor the
 * author can be moved by an update.
 *
 * `handle()` rewrites any thesis; the route rewrites only a manual one, and the
 * difference lives in LegalThesisPolicy::update(). A researched thesis is a
 * reading of an official portal, and editing it would leave a citation that no
 * longer says what the portal said.
 *
 * The URL carries two models, and route-model binding resolves both before the
 * tenant middleware runs — the thesis of another account arrives here resolved.
 * `authorize()` is the guard: the thesis must belong to the pleading in the URL,
 * the actor must be able to write that pleading, and the policy must let them
 * write that thesis.
 */
final class UpdateLegalThesis
{
    use AsAction;
    use ValidatesLegalThesis;

    public function handle(LegalThesis $thesis, LegalThesisData $data): LegalThesis
    {
        $thesis->update($data->toArray());

        return $thesis->refresh();
    }

    public function authorize(ActionRequest $request): bool
    {
        $legalCase = $request->route('legalCase');
        $thesis = $request->route('thesis');

        return $legalCase instanceof LegalCase
            && $thesis instanceof LegalThesis
            && $thesis->legal_case_id === $legalCase->id
            && $request->user()->can('update', $legalCase)
            && $request->user()->can('update', $thesis);
    }

    public function asController(LegalCase $legalCase, LegalThesis $thesis, ActionRequest $request): RedirectResponse
    {
        $this->handle($thesis, LegalThesisData::fromArray($request->validated()));

        return to_route('legal-cases.edit', [
            'legalCase' => $legalCase,
            'etapa' => LegalCaseStep::Review->value,
        ]);
    }
}
