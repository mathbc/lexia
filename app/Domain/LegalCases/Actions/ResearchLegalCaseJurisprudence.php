<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\CourtDecisions\Data\CourtDecisionListData;
use App\Domain\LegalCases\Models\LegalCase;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Researches the rulings a saved pleading can cite, and writes them down.
 *
 * The sixth step's entry point — "Análise de Jurisprudência" — and the twin
 * of `ResearchLegalCaseForensicReview` one step later. The naming follows the
 * rule the two pairs share: **the step Action is named after the step**
 * (ForensicReview, Jurisprudence) and the Action it calls is named after what
 * the agents find (Theses, CourtDecisions). `ResearchLegalCaseCourtDecisions`
 * searches the LexML and reads each record; this one decides *when* that is
 * worth paying for and gives the answer somewhere to live.
 *
 * ## Exactly once, and the lawyer asks for the rest
 *
 * The screen fires this when step 6 opens on a pleading whose
 * `court_decision_findings` is null and that has no run of its own, and never
 * again — not when the tabs change, not when the step is revisited, not on a
 * reload. It fires it through `POST /pecas/{id}/jurisprudencia/pesquisar`,
 * which points at RunLegalCaseJurisprudence: that Action queues a
 * `research_runs` row and a job in the `inference` queue, and the job calls this
 * `handle()` in a worker.
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
 * Failure is therefore total and clean: no rows, no marker, and an exception
 * that the job catches, writes to the execution log and records on the run, so
 * the screen offers the button again. That is the right shape for something
 * that fails because the LexML is down — which, being a single portal rather
 * than the three of step five, is a likelier way for this one to fail than for
 * its twin.
 */
final class ResearchLegalCaseJurisprudence
{
    use AsAction;

    public function handle(LegalCase $legalCase): LegalCase
    {
        self::refuseUnresearchable($legalCase);

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
     * The facts, as in step five. The rest of what
     * `LegalCaseDossier::forCourtDecisions()` sends — the area and the class —
     * needs no check, because both columns are NOT NULL: a pleading that exists
     * has been framed, and step 1 is what writes the row and them.
     *
     * The facts are nullable and reachable while null, and without them the
     * inner Action would throw the same refusal minutes later, after a round
     * trip to the cloud. Better here, before anything is spent — and public,
     * because RunLegalCaseJurisprudence asks it before queueing.
     */
    public static function refuseUnresearchable(LegalCase $legalCase): void
    {
        if (trim((string) $legalCase->facts) === '') {
            throw new RuntimeException('Não há fatos para pesquisar.');
        }
    }
}
