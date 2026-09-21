<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Ai\Agents\FactsRefinementAgent;
use App\Domain\LegalCases\Data\RefinedFactsData;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalCases\Support\LegalCaseDossier;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Rewrite the narrative of a pleading: formal Portuguese, chronological, and
 * carrying the weight the facts actually have.
 *
 * Takes a `LegalCase` rather than a string, which is what separates it from the
 * four Actions next door. They read a narrative and answer a question about it,
 * so the narrative is all they need; this one rewrites the narrative *of a
 * pleading*, and how it is written depends on the pleading: the area says what
 * kind of story it is, the client says who the Autor is, the defendant says how
 * to name the other side, and the requests already on file are the claims the
 * story has to carry.
 *
 * `$facts` is optional and overrides the column, because the lawyer asking for
 * this is looking at a textarea. What is on screen is what they mean, saved or
 * not, and reading the column instead would refine a version they have already
 * moved past. Omitted, the pleading's own narrative is used, which is what a
 * job or a command would want.
 *
 * Not wired into ClassifyLegalCase, and not by omission. That Action reads a
 * narrative the lawyer has just pasted in order to fill a wizard; this one
 * rewrites a narrative the lawyer is looking at, on a pleading that already
 * exists. It is a second gesture on a later screen, and it costs its own
 * `Timeout(360)`.
 *
 * No `asController()` while no route points here, exactly as its siblings. When
 * one arrives, the shell it needs is a comparison: the client's text and the
 * rewritten one side by side, with the lawyer accepting it, and with
 * `unsupportedAmounts` shown where it is not empty. An agent's prose must never
 * overwrite a client's account on its own — most of this answer is the one
 * thing in the project no guard can check afterwards, and the reasons are in
 * the agent's docblock.
 */
final class RefineLegalCaseFacts
{
    use AsAction;

    public function handle(LegalCase $legalCase, ?string $facts = null): RefinedFactsData
    {
        $narrative = trim($facts ?? (string) $legalCase->facts);

        if ($narrative === '') {
            throw new RuntimeException('Não há fatos para complementar.');
        }

        // The dossier reads four relations; a caller that arrived through
        // route-model binding has none of them loaded, and a test that built
        // the pleading by hand has all four. loadMissing is what makes both
        // cost the same.
        $legalCase->loadMissing(['customer', 'practiceArea', 'proceduralClass', 'requirements']);

        $response = (new FactsRefinementAgent(
            dossier: LegalCaseDossier::of($legalCase),
        ))->prompt($narrative);

        // The SDK returns the structured subclass for every agent declaring
        // `HasStructuredOutput`, so this narrowing never fires in practice —
        // it fires when the integration is broken, which is the same category
        // of failure the sibling Actions raise on an unknown slug or code.
        if (! $response instanceof StructuredAgentResponse) {
            throw new RuntimeException('O agente de fatos não devolveu uma resposta estruturada.');
        }

        // O relato viaja junto da resposta porque é ele que autoriza cada
        // cifra: `fromAgent()` marca todo valor que a reescrita nomeia e os
        // fatos não escrevem. Diferente do agente de pedidos, aqui a guarda
        // relata em vez de apagar — o porquê está em RefinedFactsData.
        $refined = RefinedFactsData::fromAgent($response->toArray(), $narrative);

        // An empty narrative is the one answer that cannot be handed back: the
        // caller would offer the lawyer a blank page in place of their client's
        // account. The grammar guarantees the key, never the paragraph.
        if (! $refined->isWritten()) {
            throw new RuntimeException('O agente devolveu um relato vazio.');
        }

        return $refined;
    }
}
