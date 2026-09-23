<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\CourtDecisions\Data\CourtDecisionListData;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Researches the rulings a saved pleading can cite, and writes them down.
 *
 * The seventh step's entry point — "Análise de Jurisprudência" — and the twin
 * of `ResearchLegalCaseForensicReview` one step later. The naming follows the
 * rule the two pairs share: **the step Action is named after the step**
 * (ForensicReview, Jurisprudence) and the Action it calls is named after what
 * the agents find (Theses, CourtDecisions). `ResearchLegalCaseCourtDecisions`
 * searches the LexML and reads each record; this one decides *when* that is
 * worth paying for and gives the answer somewhere to live.
 *
 * ## Exactly once, and the lawyer asks for the rest
 *
 * The screen fires this when step 7 opens on a pleading whose
 * `court_decision_findings` is null, and never again — not when the tabs
 * change, not when the step is revisited, not on a reload.
 *
 * The guard is that column and **not** the number of rulings, which is the trap
 * worth naming twice: a run that opened the portal and confirmed nothing is a
 * legitimate, expensive answer that writes zero rows, so a trigger keyed on an
 * empty list would fire on every single visit. And firing again is not merely
 * wasteful — `SaveLegalCaseCourtDecisions` reconciles by diff, deleting what
 * the payload omits, so a silent re-run would quietly replace the rulings the
 * lawyer had already read and kept. A second run is a button, pressed on
 * purpose.
 *
 * ## The two effects, and why only one of them is a transaction
 *
 * Writing the rows and marking the run are one statement about the pleading —
 * these are the rulings, and this is when we looked — so they share a
 * transaction. The inference is outside it, ahead of it, because it is slow:
 * two agents in series, the first of them on a cloud provider opening page
 * after page, and then one HTTP read per surviving ruling. A transaction held
 * open across that is a lock nobody meant to take.
 *
 * Failure is therefore total and clean: no rows, no marker, and the screen
 * offers the button again. That is the right shape for something that fails
 * because the LexML is down — which, being a single portal rather than the
 * three of step six, is a likelier way for this one to fail than for its twin.
 */
final class ResearchLegalCaseJurisprudence
{
    use AsAction;

    public function handle(LegalCase $legalCase): LegalCase
    {
        $this->refuseUnresearchable($legalCase);

        // Fora da transação de propósito: são duas inferências, uma delas na
        // nuvem, mais a leitura de um registro por julgado confirmado.
        $research = ResearchLegalCaseCourtDecisions::run($legalCase);

        return DB::transaction(function () use ($legalCase, $research): LegalCase {
            // A Action irmã faz o trabalho da tabela — o diff e o
            // `advanceTo(CourtDecisions)` — e é a mesma que o "Concluir" chama.
            SaveLegalCaseCourtDecisions::run($legalCase, CourtDecisionListData::of($research->decisions));

            $legalCase->update(['court_decision_findings' => $research->findings()]);

            return $legalCase->refresh();
        });
    }

    /**
     * The one thing the research cannot run without.
     *
     * The facts, as in step six. The rest of what
     * `LegalCaseDossier::forCourtDecisions()` sends — the area and the class —
     * needs no check, because both columns are NOT NULL: a pleading that exists
     * has been framed, and step 1 is what writes the row and them.
     *
     * The facts are nullable and reachable while null, and without them the
     * inner Action would throw the same refusal minutes later, after a round
     * trip to the cloud. Better here, before anything is spent.
     */
    private function refuseUnresearchable(LegalCase $legalCase): void
    {
        if (trim((string) $legalCase->facts) === '') {
            throw new RuntimeException('Não há fatos para pesquisar.');
        }
    }

    public function authorize(ActionRequest $request): bool
    {
        // A peça, como em toda etapa do assistente: quem pode escrevê-la pode
        // pedir a pesquisa do que ela cita.
        return $request->user()->can('update', $request->route('legalCase'));
    }

    /**
     * Answers with a redirect and not with the payload, like its twin.
     *
     * The rows are already written by the time this returns, so the honest
     * answer is "go and read the step": Inertia re-renders the form with
     * `LegalCaseFormProps::draft()`, and the rulings arrive with the ids the
     * database minted rather than ids the browser guessed.
     */
    public function asController(LegalCase $legalCase, ActionRequest $request): RedirectResponse
    {
        $this->handle($legalCase);

        return to_route('legal-cases.edit', [
            'legalCase' => $legalCase,
            'etapa' => LegalCaseStep::CourtDecisions->value,
        ]);
    }
}
