<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Ai\Agents\ForensicReviewTranscriptionAgent;
use App\Ai\Agents\LegalThesisResearchAgent;
use App\Domain\LegalCases\Data\LegalResearchData;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalCases\Support\LegalCaseDossier;
use App\Domain\LegalPrecedents\Enums\LegalPrecedentType;
use App\Domain\LegalTheses\Enums\LegalBasisType;
use App\Domain\LegalTheses\Enums\LegalThesisType;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Researches the theses a pleading can argue, in the official portals.
 *
 * The entry point for the second half of the forensic review — the sixth step.
 * Two inferences in series, and the order is imposed rather than chosen:
 * LegalThesisResearchAgent searches and writes a labelled sheet,
 * ForensicReviewTranscriptionAgent copies that sheet into the nested structure.
 *
 * ## Why two calls and not one
 *
 * Because on Gemini a schema and a web search cannot be asked for together.
 * Passing `response_json_schema` switches the grounding off **silently**: the
 * request returns 200, the JSON is well formed, and the model has searched
 * nothing. Measured, with the table, in LegalThesisResearchAgent's docblock.
 * Splitting the work is what keeps the search real, and it costs one inference
 * — the trade `app/Ai/README.md` already made for the area and the class.
 *
 * ## The two failures, and why only one of them is fatal
 *
 * A research run that confirms nothing is a legitimate answer: empty lists and
 * a populated `pending`. A run that comes back *blank* is not — the transcriber
 * would be handed nothing and would answer about nothing, which is the one way
 * this pair can produce an empty review that means "the agent broke" while
 * looking exactly like "the law does not help you here". So an empty sheet
 * raises, in the same spirit as `RefineLegalCaseFacts` refusing an empty
 * narrative, and for the same reason: the grammar guarantees the key, never the
 * content.
 *
 * ## Where the consulted urls come from
 *
 * Two places, merged, and they are not equally trustworthy. The agent lists
 * what it says it opened; the provider reports what it actually fetched, in the
 * grounding metadata the SDK surfaces as `meta->citations`. The second cannot
 * be hallucinated, which is why it is collected at all.
 *
 * It is also, on Gemini, usually unusable: this provider hands back Vertex
 * redirect urls rather than the page's own address, so they fail
 * OfficialLegalSources and drop out of `sources`. That is the honest outcome
 * and not a bug to paper over — the alternative would be crediting
 * `vertexaisearch.cloud.google.com` as an official portal. The collection stays
 * because it costs nothing and because a provider that returns real urls — as
 * Anthropic does — makes it immediately worth having.
 *
 * No `asController()`, exactly as its siblings: nothing routes here yet. When
 * something does, this is a queue and not a request — two `Timeout(180)` in
 * series with a browser waiting is the debt `ClassifyLegalCase` already
 * documents, and the first of the two is the slowest inference in the project,
 * because the provider runs the searches before it answers.
 */
final class ResearchLegalCaseTheses
{
    use AsAction;

    public function handle(LegalCase $legalCase, ?string $facts = null): LegalResearchData
    {
        $narrative = trim($facts ?? (string) $legalCase->facts);

        if ($narrative === '') {
            throw new RuntimeException('Não há fatos para pesquisar.');
        }

        // Três relações, e não as quatro do dossiê completo: o cliente e o réu
        // não entram na pesquisa. loadMissing é o que faz custar o mesmo para
        // quem chegou por route-model binding e para um teste que montou a peça
        // à mão.
        $legalCase->loadMissing(['practiceArea', 'proceduralClass', 'requirements']);

        $sheet = $this->research($legalCase, $narrative);

        return LegalResearchData::fromAgent(
            $this->transcribe($sheet->text),
            $this->consultedUrls($sheet),
        );
    }

    /**
     * The search, which is the half that reaches the internet.
     */
    private function research(LegalCase $legalCase, string $narrative): AgentResponse
    {
        $sheet = (new LegalThesisResearchAgent(
            dossier: LegalCaseDossier::forResearch($legalCase),
            thesisTypes: array_column(LegalThesisType::cases(), 'value'),
            basisTypes: array_column(LegalBasisType::cases(), 'value'),
            precedentTypes: array_column(LegalPrecedentType::cases(), 'value'),
        ))->prompt($narrative);

        if (trim($sheet->text) === '') {
            throw new RuntimeException('O agente de pesquisa não devolveu nada.');
        }

        return $sheet;
    }

    /**
     * The transcription, which reaches nothing.
     *
     * @return array<string, mixed>
     */
    private function transcribe(string $sheet): array
    {
        $response = (new ForensicReviewTranscriptionAgent(
            thesisTypes: array_column(LegalThesisType::cases(), 'value'),
            basisTypes: array_column(LegalBasisType::cases(), 'value'),
            precedentTypes: array_column(LegalPrecedentType::cases(), 'value'),
        ))->prompt($sheet);

        // The SDK returns the structured subclass for every agent declaring
        // `HasStructuredOutput`, so this narrowing never fires in practice — it
        // fires when the integration is broken, which is the same category of
        // failure the sibling Actions raise on an unknown slug or code.
        if (! $response instanceof StructuredAgentResponse) {
            throw new RuntimeException('O agente de transcrição não devolveu uma resposta estruturada.');
        }

        return $response->toArray();
    }

    /**
     * The urls the provider itself reported opening.
     *
     * Read off the grounding metadata rather than out of the answer, which is
     * what makes this the one part of the payload the model cannot invent. See
     * the class docblock for why it is usually empty on Gemini anyway.
     *
     * @return list<string>
     */
    private function consultedUrls(AgentResponse $sheet): array
    {
        return $sheet->meta->citations
            ->filter(static fn (mixed $citation): bool => $citation instanceof UrlCitation)
            ->map(static fn (UrlCitation $citation): string => $citation->url)
            ->values()
            ->all();
    }
}
