<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Ai\Agents\FactsScreeningAgent;
use App\Domain\LegalCases\Data\FactsScreeningData;
use App\Rag\KnowledgeBase;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Say whether a narrative is the story of a legal matter, before anything is
 * spent framing it.
 *
 * ClassifyLegalCase is the only caller, and calls it first and alone: the
 * point is that a refused text never reaches the block. No `asController()`,
 * because nothing points at it; the "Consultar IA" buttons of the first step
 * read facts the lawyer typed into a pleading by hand, and a door there would
 * be another decision.
 *
 * Only the narrative travels. The screening needs no party and no catalogue —
 * the question is whether there is a case at all, which comes before knowing
 * whose it is.
 */
final class ScreenLegalCaseFacts
{
    use AsAction;

    public function __construct(
        private readonly KnowledgeBase $knowledge,
    ) {}

    public function handle(string $facts): FactsScreeningData
    {
        $facts = trim($facts);

        if ($facts === '') {
            throw new RuntimeException('Não há fatos para triar.');
        }

        $response = (new FactsScreeningAgent(
            knowledge: $this->knowledge->get('facts-screening'),
        ))->prompt($facts);

        if (! $response instanceof StructuredAgentResponse) {
            throw new RuntimeException('O agente de triagem não devolveu uma resposta estruturada.');
        }

        return FactsScreeningData::fromAgent($response->toArray());
    }
}
