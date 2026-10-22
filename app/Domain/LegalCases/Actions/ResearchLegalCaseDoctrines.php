<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Ai\Agents\DoctrineResearchAgent;
use App\Ai\Agents\DoctrineTranscriptionAgent;
use App\Domain\Doctrines\Support\DoctrineRecordReader;
use App\Domain\LegalCases\Data\DoctrineResearchData;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalCases\Support\LegalCaseDossier;
use App\Domain\Shared\Support\GroundingRedirect;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Researches the legal scholarship a pleading may lean on — the third tab of the
 * fifth step.
 *
 * ResearchLegalCaseCourtDecisions, step for step, against the LexML's library
 * records instead of its rulings: DoctrineResearchAgent searches and writes a
 * labelled sheet, DoctrineTranscriptionAgent copies it into the flat structure,
 * and DoctrineRecordReader reads each record so that author, title and reference
 * are the catalogue's. Two inferences in series and then PHP, for the reasons
 * that class's docblock gives — on Gemini a schema switches the search off, and
 * what a pleading prints about a work must not come from a model.
 *
 * The one difference is **where the addresses come from**, and it was measured
 * rather than chosen: the agent invents the catalogue number of every URN it
 * writes (GroundingRedirect has the table), so the records that get read are the
 * ones the *provider* returned — its grounding citations, followed one hop — and
 * the agent's works are matched to them by title and author. See
 * DoctrineResearchData for the match and the five.
 *
 * A blank sheet raises rather than transcribing nothing, which would look
 * exactly like "the catalogue has no work on this".
 *
 * Writes nothing: ResearchLegalCaseForensicReview calls it as one task of its
 * `Concurrency::run`, beside the theses and the themes, and writes what comes
 * back. No `asController()`, because no route points here.
 */
final class ResearchLegalCaseDoctrines
{
    use AsAction;

    public function __construct(
        private readonly DoctrineRecordReader $reader = new DoctrineRecordReader,
    ) {}

    public function handle(LegalCase $legalCase): DoctrineResearchData
    {
        $narrative = trim((string) $legalCase->facts);

        if ($narrative === '') {
            throw new RuntimeException('Não há fatos para pesquisar.');
        }

        $legalCase->loadMissing(['practiceArea', 'proceduralClass']);

        $sheet = $this->research($legalCase, $narrative);

        $found = DoctrineResearchData::fromAgent(
            $this->transcribe($sheet->text),
            $this->groundedUrls($sheet),
        );

        // Só aqui a obra passa a ser a do catálogo: o que veio das duas
        // inferências são nomes e uma nota; autor, título e referência são
        // lidos da página que o provedor devolveu.
        return $found->readRecords($this->reader->read(...), $this->reader->articlesIn(...));
    }

    /**
     * The search, which is the half that reaches the internet.
     */
    private function research(LegalCase $legalCase, string $narrative): AgentResponse
    {
        $sheet = (new DoctrineResearchAgent(
            dossier: LegalCaseDossier::forDoctrines($legalCase),
        ))->prompt($narrative);

        if (trim($sheet->text) === '') {
            throw new RuntimeException('O agente de doutrina não devolveu nada.');
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
        $response = (new DoctrineTranscriptionAgent)->prompt($sheet);

        if (! $response instanceof StructuredAgentResponse) {
            throw new RuntimeException('O agente de transcrição não devolveu uma resposta estruturada.');
        }

        return $response->toArray();
    }

    /**
     * The pages the provider's search actually returned.
     *
     * Every grounding chunk of the raw Gemini answer, and not only the
     * citations: the SDK keeps as citations just the chunks the text leans on,
     * and a sheet that writes `URL: nulo` — as the prompt asks when the address
     * was not seen — leans on few of them. Measured on the second live run, the
     * citations held one library record; the chunks are the whole list the
     * search returned. The citations are kept too, for a provider that has no
     * raw body.
     *
     * Read off the provider's metadata — the one part of the exchange no model
     * wrote — and followed one hop, because on Gemini every one is a Vertex
     * redirect. A redirect that cannot be followed is dropped; one that lands
     * outside the library network is dropped later, by the guard.
     *
     * @return list<string>
     */
    private function groundedUrls(AgentResponse $sheet): array
    {
        $chunks = $sheet->raw?->json('candidates.0.groundingMetadata.groundingChunks');

        $chunkUrls = collect(is_array($chunks) ? $chunks : [])
            ->map(static fn (mixed $chunk): mixed => is_array($chunk) ? ($chunk['web']['uri'] ?? null) : null);

        $citationUrls = $sheet->meta->citations
            ->filter(static fn (mixed $citation): bool => $citation instanceof UrlCitation)
            ->map(static fn (UrlCitation $citation): string => $citation->url);

        return $chunkUrls->merge($citationUrls)
            ->filter(static fn (mixed $url): bool => is_string($url) && $url !== '')
            ->unique()
            ->map(static fn (string $url): ?string => GroundingRedirect::resolve($url))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
