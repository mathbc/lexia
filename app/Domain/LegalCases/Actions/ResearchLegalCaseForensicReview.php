<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Researches the theses a saved pleading can argue, and writes them down.
 *
 * The forensic review's other half, and the reason step 6 is no longer a screen
 * that shows something the tab is about to forget.
 *
 * ## Why this exists rather than living in ClassifyLegalCase
 *
 * The research used to be the fifth step of the smart fill, and two things sent
 * it here.
 *
 * The first is time. It is the only inference in this project that leaves the
 * machine, and it opens the official portals before answering, so it is by some
 * margin the slowest thing we run. Sitting inside the classification it
 * cancelled out the concurrent block entirely: shortening the four local steps
 * does not shorten this one, and the lawyer waited for it before seeing a
 * single field filled in.
 *
 * The second decides it. A pleading that has not been saved has no primary key,
 * so there was nowhere to put what the research found — the theses came back in
 * the JSON, travelled through `sessionStorage`, and died with the tab. Minutes
 * of inference and a Gemini bill, gone to an F5.
 *
 * Here both are answered by the same fact: the pleading exists. The research
 * runs when the lawyer reaches the step that needs it, and it is written in the
 * same gesture. This is also why the wizard makes step 1 save before any other
 * step can be opened — without a primary key there is nothing for this Action
 * to be called on.
 *
 * ## Exactly once, and the lawyer asks for the rest
 *
 * The screen fires this when step 6 opens on a pleading whose
 * `research_findings` is null, and never again — not when the tabs change, not
 * when the step is revisited, not on a reload.
 *
 * The guard is that column and **not** the number of theses, which is the trap
 * worth naming: a run that confirms nothing is a legitimate, expensive answer
 * that writes zero theses, so a trigger keyed on an empty list would fire on
 * every single visit. And firing again is not merely wasteful —
 * `SaveLegalCaseForensicReview` reconciles by diff, deleting what the payload
 * omits, so a silent re-run would quietly replace theses the lawyer had already
 * curated. A second run is a button, pressed on purpose.
 *
 * ## The two effects, and why only one of them is a transaction
 *
 * Writing the rows and marking the run are one statement about the pleading —
 * these are the theses, and this is when we looked — so they share a
 * transaction. The inference is outside it, ahead of it, because it is slow and
 * because a transaction held open across a network call to another continent is
 * a lock nobody meant to take.
 *
 * Failure is therefore total and clean: no rows, no marker, and the screen
 * offers the button again. That is the right shape for something that fails
 * because the STJ is down.
 *
 * No `stage()`-style swallowing here, unlike ClassifyLegalCase. There the
 * research was a bonus beside a framing that had already cost minutes; here it
 * is the whole request, and a lawyer who clicked into step 6 needs to be told
 * it did not work rather than shown an empty step that looks researched.
 */
final class ResearchLegalCaseForensicReview
{
    use AsAction;

    public function handle(LegalCase $legalCase): LegalCase
    {
        $this->refuseUnresearchable($legalCase);

        // Fora da transação de propósito: são duas inferências, uma delas na
        // nuvem e abrindo páginas, e nada disso deve segurar uma linha travada.
        $research = ResearchLegalCaseTheses::run($legalCase);

        return DB::transaction(function () use ($legalCase, $research): LegalCase {
            // A Action irmã faz o trabalho inteiro das duas tabelas — o mapa de
            // ids, o diff, o `advanceTo(Review)` — e é a mesma que o "Concluir"
            // chama. Duplicá-la aqui seria manter duas gravações da mesma coisa.
            SaveLegalCaseForensicReview::run($legalCase, $research->review);

            $legalCase->update(['research_findings' => $research->findings()]);

            return $legalCase->refresh();
        });
    }

    /**
     * The one thing the research cannot run without.
     *
     * The facts, and only the facts. The other half of what
     * `LegalCaseDossier::forResearch()` sends — the area — needs no check here
     * because `legal_cases.practice_area_id` is NOT NULL: a pleading that
     * exists has an area, and step 1 is what writes both the row and it.
     *
     * The facts are nullable, though, and reachable while null: step 1 carries
     * them only when the pleading is born of the smart fill, and otherwise they
     * arrive at step 3. Without them the agent has nothing to research and the
     * inner Action would throw the same refusal minutes later, after a
     * round trip to the cloud. Better here, before anything is spent.
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
        // pedir a pesquisa do que ela argumenta.
        return $request->user()->can('update', $request->route('legalCase'));
    }

    /**
     * Answers with a redirect and not with the payload, unlike the smart fill.
     *
     * `POST /pecas/classificar` hands its result to the browser because there is
     * nothing to read it back from — the pleading does not exist yet. Here it
     * does, and the rows are already written by the time this returns, so the
     * honest answer is "go and read the step": Inertia re-renders the form with
     * `LegalCaseFormProps::draft()`, and the theses arrive with the ids the
     * database minted rather than ids the browser guessed.
     */
    public function asController(LegalCase $legalCase, ActionRequest $request): RedirectResponse
    {
        $this->handle($legalCase);

        return to_route('legal-cases.edit', [
            'legalCase' => $legalCase,
            'etapa' => LegalCaseStep::Review->value,
        ]);
    }
}
