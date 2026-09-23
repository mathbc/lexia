<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Ai\Agents\CourtDecisionResearchAgent;
use App\Ai\Agents\CourtDecisionTranscriptionAgent;
use App\Domain\CourtDecisions\Support\LexmlRecordReader;
use App\Domain\LegalCases\Data\CourtDecisionResearchData;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalCases\Support\LegalCaseDossier;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Researches the rulings the courts have handed down in cases like this one.
 *
 * The entry point for the seventh step — "Análise de Jurisprudência". Two
 * inferences in series, and the order is imposed rather than chosen:
 * CourtDecisionResearchAgent searches the LexML and writes a labelled sheet,
 * CourtDecisionTranscriptionAgent copies that sheet into the flat structure.
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
 * ## Why it is not an HTTP call against the SRU endpoint
 *
 * The obvious cheaper design, and it does not work. The LexML publishes an SRU
 * API, but it sits under `/busca/`, which the portal's `robots.txt` disallows
 * and which answers a plain client with the Senado's anti-bot challenge — a
 * page of JavaScript proof-of-work, not XML. What *is* reachable is the
 * per-document page under `/urn/`, which is also what Google indexes, which is
 * why this is a grounded search rather than a parser. CourtDecisionSources
 * records the measurements.
 *
 * ## Three steps, and the third one is not an inference
 *
 * The pair finds rulings; it does not quote them. Every decision that survives
 * the guard is then **read from its own LexML record by LexmlRecordReader**,
 * and the ementa, the título, the autoridade, a localidade and a data come from
 * that page rather than from either model.
 *
 * That is not belt-and-braces, it is the only way the ementa can exist at all:
 * asking Gemini to reproduce a published headnote verbatim comes back
 * `finishReason: RECITATION` with an empty candidate, and asking it not to
 * comes back with the headnote quietly abridged. Both measurements are in
 * LexmlRecordReader's docblock. A ruling whose record does not answer is
 * dropped and reported, which is why the count on the screen can be smaller
 * than the count in the sheet.
 *
 * ## The two failures, and why only one of them is fatal
 *
 * A run that confirms nothing is a legitimate answer: an empty list and a
 * populated `pending`. A run that comes back *blank* is not — the transcriber
 * would be handed nothing and would answer about nothing, which is the one way
 * this pair can produce an empty list that means "the agent broke" while
 * looking exactly like "the courts have not decided this". So an empty sheet
 * raises, in the same spirit as `ResearchLegalCaseTheses` and for the same
 * reason: the grammar guarantees the key, never the content.
 *
 * Note what is *not* enforced here: `CourtDecisionResearchData::MIN_DECISIONS`.
 * The prompt asks for three and the agent test holds the agent to three, but
 * two confirmed rulings are a real answer and padding them would be the failure
 * this design exists to refuse.
 *
 * ## Where the consulted urls come from
 *
 * Two places, merged, and they are not equally trustworthy. The agent lists
 * what it says it opened; the provider reports what it actually fetched, in the
 * grounding metadata the SDK surfaces as `meta->citations`. The second cannot
 * be hallucinated, which is why it is collected at all — and on Gemini it is
 * usually unusable, because this provider hands back Vertex redirect urls
 * rather than the page's own address, so they fail the guard and drop out of
 * `sources`. That is the honest outcome rather than a bug to paper over: the
 * alternative would be crediting `vertexaisearch.cloud.google.com` as a court
 * record.
 *
 * ## Who calls it, and what that costs
 *
 * `ResearchLegalCaseJurisprudence`, which is the seventh step opening — and
 * nobody else. There is still no `asController()` here, because no route points
 * at this Action: what the route points at is the step, which decides whether
 * the run is worth paying for and writes down what comes back. That route
 * carries the `inference` middleware, like its four siblings: two serial
 * `Timeout(360)` are far past the 30 seconds a stock `php.ini` allows, and the
 * request would be killed inside cURL with nothing to catch.
 *
 * The pleading it receives need not be saved. `forCourtDecisions()` only ever
 * touches the model it is handed, so the two relations set by hand are the
 * whole of the contract — `tests/Agents/CourtDecisionResearchTest` builds one
 * that way.
 */
final class ResearchLegalCaseCourtDecisions
{
    use AsAction;

    public function __construct(
        private readonly LexmlRecordReader $reader = new LexmlRecordReader,
    ) {}

    public function handle(LegalCase $legalCase, ?string $facts = null): CourtDecisionResearchData
    {
        $narrative = trim($facts ?? (string) $legalCase->facts);

        if ($narrative === '') {
            throw new RuntimeException('Não há fatos para pesquisar.');
        }

        // Duas relações, e não as três da pesquisa de teses: os pedidos não
        // entram aqui. loadMissing é o que faz custar o mesmo para quem chegou
        // por route-model binding e para um teste que montou a peça à mão.
        $legalCase->loadMissing(['practiceArea', 'proceduralClass']);

        $sheet = $this->research($legalCase, $narrative);

        $found = CourtDecisionResearchData::fromAgent(
            $this->transcribe($sheet->text),
            $this->consultedUrls($sheet),
        );

        // E só aqui a jurisprudência passa a ser a do tribunal: o que veio das
        // duas inferências são endereços, e a ementa é lida da página. Fora da
        // transação e fora do modelo, pelos dois motivos que a classe do leitor
        // documenta.
        return $found->readRecords($this->reader->read(...));
    }

    /**
     * The search, which is the half that reaches the internet.
     */
    private function research(LegalCase $legalCase, string $narrative): AgentResponse
    {
        $sheet = (new CourtDecisionResearchAgent(
            dossier: LegalCaseDossier::forCourtDecisions($legalCase),
        ))->prompt($narrative);

        if (trim($sheet->text) === '') {
            throw new RuntimeException('O agente de jurisprudência não devolveu nada.');
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
        $response = (new CourtDecisionTranscriptionAgent)->prompt($sheet);

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
