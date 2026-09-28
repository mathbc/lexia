<?php

declare(strict_types=1);

namespace App\Domain\LegalTheses\Actions;

use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalTheses\Actions\Concerns\ValidatesLegalThesis;
use App\Domain\LegalTheses\Data\LegalThesisData;
use App\Domain\LegalTheses\Enums\LegalThesisOrigin;
use App\Domain\LegalTheses\Models\LegalThesis;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Registers one thesis the lawyer wrote by hand — the "Cadastrar tese" of step 5.
 *
 * The research can fail, or open every portal and confirm nothing, and a lawyer
 * who knows the argument should not have to wait for a machine to find it. So
 * the step offers a modal, and this is where it posts.
 *
 * A component on its own, next to SaveLegalCaseForensicReview rather than
 * inside it: that one writes the step, which is both lists at once and the diff
 * between what was there and what came back. This one adds a single row and
 * touches nothing else. The row is written **now**, not at the "Concluir", so it
 * survives a reload like the researched ones do; unticking it still removes it
 * there, because the "Concluir" posts only what stayed ticked.
 *
 * It is stamped `Manual`, and this is the only place that writes the value. That
 * is what makes it editable (UpdateLegalThesis) and what keeps it through
 * "Pesquisar novamente" (ResearchLegalCaseForensicReview::writeTheses()).
 *
 * The account comes from the pleading and not from the actor, the same rule
 * `SaveLegalCaseForensicReview::blankThesis()` follows: it is the pleading that
 * says which tenant the argument belongs to.
 */
final class CreateLegalThesis
{
    use AsAction;
    use ValidatesLegalThesis;

    public function handle(LegalCase $legalCase, LegalThesisData $data): LegalThesis
    {
        $thesis = new LegalThesis([
            'account_id' => $legalCase->account_id,
            'legal_case_id' => $legalCase->id,
            'origin' => LegalThesisOrigin::Manual,
        ]);

        // toArray() não devolve `id` nem `origin`: a chave é cunhada pelo
        // HasUuids, e a origem é a desta Action, nunca a do payload.
        $thesis->fill($data->toArray())->save();

        return $thesis;
    }

    public function authorize(ActionRequest $request): bool
    {
        // A peça, como em toda etapa do assistente: quem pode escrevê-la pode
        // escrever o que ela argumenta.
        return $request->user()->can('update', $request->route('legalCase'));
    }

    /**
     * Back to the step, like the research: the row is written, and Inertia
     * re-renders the form with it among the others.
     */
    public function asController(LegalCase $legalCase, ActionRequest $request): RedirectResponse
    {
        $this->handle($legalCase, LegalThesisData::fromArray($request->validated()));

        return to_route('legal-cases.edit', [
            'legalCase' => $legalCase,
            'etapa' => LegalCaseStep::Review->value,
        ]);
    }
}
