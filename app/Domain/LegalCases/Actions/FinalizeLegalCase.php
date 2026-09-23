<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\CourtDecisions\Data\CourtDecisionListData;
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
 *
 * ## The seventh step travels with it
 *
 * Since "Análise de Jurisprudência" arrived, the button that finishes a pleading
 * sits on *that* step rather than on the forensic review, and it carries two
 * lists rather than one: the theses the lawyer kept, and the rulings they kept.
 * Both are already rows — the two research runs wrote them — and what the
 * conclusion posts is the reading, so both saves are diffs and both unticks are
 * deletions. They share the transaction for the reason the first two effects
 * share it: it is one statement about the pleading, and a conclusion that
 * pruned the theses but not the case law would be half a decision.
 *
 * The order matters for the document too: the drafting runs after the
 * transaction, on a refreshed pleading, so the rulings the document quotes are
 * exactly the ones this conclusion kept — an unticked ruling is already deleted
 * when `LegalCaseDossier::forDrafting()` reads the relation. The precedents of
 * the forensic review stay out of the document; the dossier says why.
 */
final class FinalizeLegalCase
{
    use AsAction;

    public function handle(
        LegalCase $legalCase,
        ForensicReviewData $data,
        CourtDecisionListData $decisions,
        ?User $author = null,
    ): LegalCase {
        DB::transaction(function () use ($legalCase, $data, $decisions): void {
            // A Action irmã, e não uma cópia dela: é ela que guarda o mapa do id
            // postado para o id persistido, sem o qual um precedente aponta para
            // uma tese que ainda não tinha chave.
            SaveLegalCaseForensicReview::run($legalCase, $data);

            // E a da etapa 7, pelo mesmo motivo: o diff que apaga o que o
            // advogado desmarcou mora lá, com a marca d'água que ele move.
            SaveLegalCaseCourtDecisions::run($legalCase, $decisions);

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
     * The sixth and seventh steps' payloads, unchanged.
     *
     * Delegated to the Actions that save them rather than restated, so the
     * routes cannot drift into disagreeing about what a thesis or a ruling looks
     * like. The reasoning behind each rule — why `theses.*.id` is a hint and
     * never a key, why `legal_thesis_id` carries no `exists`, why the portal
     * guard is not a validation rule — lives there.
     *
     * Both lists are `present`: the wizard's last step holds them both, and a
     * conclusion that omitted one would be saying "leave those rows alone",
     * which is a sentence no screen here means.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...SaveLegalCaseForensicReview::make()->rules(),
            ...SaveLegalCaseCourtDecisions::make()->rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return [
            ...SaveLegalCaseForensicReview::make()->getValidationAttributes(),
            ...SaveLegalCaseCourtDecisions::make()->getValidationAttributes(),
        ];
    }

    /**
     * Lands on the Minuta tab, and not back on the wizard.
     *
     * Concluding is the one save in this flow that changes which screen the
     * lawyer belongs on: every other step redirects to the next step, and there
     * is no step after the seventh. The document is what they came for.
     */
    public function asController(LegalCase $legalCase, ActionRequest $request): RedirectResponse
    {
        $this->handle(
            $legalCase,
            ForensicReviewData::fromArray($request->validated()),
            CourtDecisionListData::fromArray($request->validated()),
            $request->user(),
        );

        return to_route('legal-cases.pleading', ['legalCase' => $legalCase]);
    }
}
