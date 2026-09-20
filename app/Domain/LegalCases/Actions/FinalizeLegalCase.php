<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\LegalCases\Data\ForensicReviewData;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\Users\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * Close a pleading: save what it argues, take it out of draft, and draft the
 * document.
 *
 * **The first Action in this project that finishes anything.** `is_draft` has
 * had a column, an index, a filter, a badge and a factory state since the
 * beginning, and nothing ever wrote `false` to it — LegalCasePolicy says as
 * much. This is the gesture the sixth step was missing: until now the forensic
 * review was a screen with no button, and the theses the research had found
 * lived in `sessionStorage` until the tab was closed.
 *
 * Three things happen, and the boundary between them is the whole design.
 *
 * The first two are **one transaction**, because they are one statement about
 * the pleading: these are the arguments it carries, and it is finished. Saving
 * the theses and leaving the flag set would produce a pleading that is done
 * arguing and still a draft, which is not a state the product has a word for.
 *
 * The third — drafting the document — is **outside**, with its own try/catch,
 * and that is deliberate. It is the only part that leaves the machine, it is the
 * only part that takes minutes, and it is the only part a provider quota or a
 * dropped connection can take away. The same reasoning as
 * `ClassifyLegalCase::stage()`: a failure there must not undo work that
 * succeeded, so the pleading stays registered, the error reaches the log through
 * `report()`, and the Minuta tab opens empty offering to try again. The lawyer
 * who spent minutes on the research does not lose it because a portal was down.
 *
 * The existing `PUT /revisao-forense` stays where it is. It saves the review
 * without finishing the pleading, which is a different use case — this one calls
 * it rather than reimplementing the map of posted ids to persisted ones that
 * makes it work.
 */
final class FinalizeLegalCase
{
    use AsAction;

    public function handle(LegalCase $legalCase, ForensicReviewData $data, ?User $author = null): LegalCase
    {
        DB::transaction(function () use ($legalCase, $data): void {
            // A Action irmã, e não uma cópia dela: é ela que guarda o mapa do id
            // postado para o id persistido, sem o qual um precedente aponta para
            // uma tese que ainda não tinha chave.
            SaveLegalCaseForensicReview::run($legalCase, $data);

            $legalCase->update(['is_draft' => false]);
        });

        // Fora da transação de propósito: ver o docblock. Uma cota estourada não
        // pode desfazer a gravação das teses.
        try {
            DraftLegalPleading::run($legalCase->refresh(), $author);
        } catch (Throwable $e) {
            report($e);
        }

        return $legalCase->refresh();
    }

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('update', $request->route('legalCase'));
    }

    /**
     * The sixth step's payload, unchanged.
     *
     * Delegated to the Action that saves it rather than restated, so the two
     * routes cannot drift into disagreeing about what a thesis looks like. The
     * reasoning behind each rule — why `theses.*.id` is a hint and never a key,
     * why `legal_thesis_id` carries no `exists` — lives there.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return SaveLegalCaseForensicReview::make()->rules();
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return SaveLegalCaseForensicReview::make()->getValidationAttributes();
    }

    /**
     * Lands on the Minuta tab, and not back on the wizard.
     *
     * Concluding is the one save in this flow that changes which screen the
     * lawyer belongs on: every other step redirects to the next step, and there
     * is no step after the sixth. The document is what they came for.
     */
    public function asController(LegalCase $legalCase, ActionRequest $request): RedirectResponse
    {
        $this->handle(
            $legalCase,
            ForensicReviewData::fromArray($request->validated()),
            $request->user(),
        );

        return to_route('legal-cases.pleading', ['legalCase' => $legalCase]);
    }
}
