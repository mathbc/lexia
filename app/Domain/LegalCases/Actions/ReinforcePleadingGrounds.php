<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Ai\Agents\PleadingGroundsReinforcementAgent;
use App\Domain\LegalCases\Data\ReinforcedGroundsData;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalCases\Support\LegalCaseDossier;
use App\Domain\LegalPleadings\Support\PleadingSections;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Reinforce the argument of a drafted pleading's DO DIREITO section, and hand
 * back the whole draft with the section replaced.
 *
 * The second half of the drafting pair, in the shape ResearchLegalCaseTheses set
 * for the research pair: two agents in series inside PHP, the first one's answer
 * becoming the second one's prompt. It is its own Action, rather than a private
 * method of DraftLegalPleading, so it can be called — and faked — on its own.
 *
 * It takes the draft and not the stored version, because it runs **before**
 * anything is stored: the markers are still markers, the signature is not there
 * yet, and the version the lawyer sees is the reinforced one or, when this
 * throws, the one the drafting agent wrote.
 *
 * Two answers are not failures, and come back as the draft untouched: a draft
 * with no DO DIREITO this class can find (see PleadingSections), and a pleading
 * with no theses — the theses are what the argument is reinforced *with*, and
 * without them the only material left is the model's memory.
 *
 * Everything else that goes wrong throws, including a rewrite that
 * ReinforcedGroundsData refuses. The caller reports it and keeps the draft.
 *
 * No `asController()`: DraftLegalPleading is the only caller.
 */
final class ReinforcePleadingGrounds
{
    use AsAction;

    public function handle(LegalCase $legalCase, string $draft): string
    {
        $grounds = PleadingSections::grounds($draft);

        // A mesma leitura de relações que DraftLegalPleading já fez; quem chama
        // sozinho — um teste, uma etapa futura — paga as cinco aqui.
        $legalCase->loadMissing(['practiceArea', 'proceduralClass', 'requirements', 'theses', 'courtDecisions']);

        if ($grounds === null || $grounds === '' || $legalCase->theses->isEmpty()) {
            return $draft;
        }

        $narrative = trim((string) $legalCase->facts);
        $dossier = LegalCaseDossier::forGrounds($legalCase);

        $response = (new PleadingGroundsReinforcementAgent(
            dossier: $dossier,
            facts: $narrative,
        ))->prompt($grounds);

        if (! $response instanceof StructuredAgentResponse) {
            throw new RuntimeException('O agente de embasamento não devolveu uma resposta estruturada.');
        }

        // O dossiê e o relato são o que autoriza uma cifra ou uma súmula; o
        // corpo original entra junto dentro de `fromAgent()`, porque o que a
        // redação já citou não precisa de nova licença.
        $reinforced = ReinforcedGroundsData::fromAgent($response->toArray(), $grounds, $dossier.' '.$narrative);

        if (! $reinforced->isAcceptable()) {
            throw new RuntimeException((string) $reinforced->refusal());
        }

        return PleadingSections::withGrounds($draft, $reinforced->content);
    }
}
