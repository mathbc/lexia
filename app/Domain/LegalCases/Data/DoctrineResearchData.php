<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Data;

use App\Domain\Doctrines\Data\DoctrineData;
use App\Domain\Doctrines\Support\DoctrineSources;
use Carbon\CarbonImmutable;

/**
 * What a doctrine run found in the LexML, and what it had to throw away.
 *
 * CourtDecisionResearchData for scholarship, on the same two layers — a list
 * that becomes rows, and the account of the run around it —, with one
 * difference that decides how the list is built.
 *
 * ## The agent names the works; the provider says where they are
 *
 * The first live run returned seven real-looking works at seven addresses, and
 * **none of the seven existed**: the agent got the kind and the year of each
 * URN right and invented the catalogue number, which nothing it saw contains.
 * The addresses the *provider* returned — the grounding chunks, followed one hop
 * by GroundingRedirect — were real records. So the two sources play different
 * parts here:
 *
 * - `sources` are the records the provider returned (the `$grounded` urls),
 *   and they are the pool of addresses that get read;
 * - the agent's candidates bring the **ranking** and the **note** — and a title
 *   and an author, which is how each one is matched to a record that was read
 *   (`DoctrineData::names()`). Its own address joins the pool too, since
 *   reading costs little and is sometimes right, but it confirms nothing alone.
 *
 * A candidate no record matches is reported in `unverifiedCitations`: the agent
 * named a work and nothing confirmed it. A record nobody named — the search
 * returned it for the case's own terms — still fills the list after the matched
 * ones, without a note, until `WANTED`.
 *
 * ## The journal the search returned instead of the article
 *
 * Measured on the third live run: the agent named four real articles, and the
 * search had returned the **periodicals** they appeared in — a `:revista:`
 * record, which is not doctrine and which the guard refuses. But a periodical's
 * page lists every article it published, each linked to the article's own
 * record. So a candidate still unmatched after the pool is looked up in the
 * indexes of the periodicals the provider returned (`$periodicals`, at most
 * `MAX_INDEXES` of them), and an entry that names it is read like any other
 * record. The entry confirms nothing alone: what is shown is the page it points
 * at.
 *
 * ## Five, read in order
 *
 * The tab promises five. The prompt asks for five works plus reserves, the
 * grammar caps the candidates at `CANDIDATES`, and at most `MAX_READS` records
 * are read per run. Fewer than five is an answer; nothing pads it with a work
 * no record holds.
 *
 * Crosses `serialize()` on its way out of a child process
 * (ResearchLegalCaseForensicReview), so it holds strings and readonly DTOs only.
 */
final readonly class DoctrineResearchData
{
    /**
     * How many works the tab shows at most, and what the prompt asks for.
     */
    public const WANTED = 5;

    /**
     * What the grammar imposes on the agent: the five, plus two in reserve.
     */
    public const CANDIDATES = 7;

    /**
     * How many records one run reads at most, ten seconds each in the worst case.
     */
    public const MAX_READS = 12;

    /**
     * How many periodical indexes one run reads at most. They are large pages —
     * a journal's whole run of articles —, so the ceiling is low.
     */
    public const MAX_INDEXES = 4;

    /**
     * @param  list<string>  $searchTerms  what the agent condensed the case into
     * @param  list<DoctrineData>  $doctrines  the agent's candidates, then the records
     * @param  list<string>  $sources  the library records the provider returned
     * @param  list<string>  $pending  what the research could not resolve
     * @param  list<string>  $unverifiedCitations  works the agent named and no record confirmed
     * @param  list<string>  $periodicals  the periodical records the provider returned
     */
    public function __construct(
        public array $searchTerms,
        public array $doctrines,
        public array $sources = [],
        public array $pending = [],
        public array $unverifiedCitations = [],
        public array $periodicals = [],
    ) {}

    /**
     * The transcription agent's answer, as candidates, beside what the provider
     * actually returned.
     *
     * @param  array<string, mixed>  $answer
     * @param  list<string>  $grounded  the provider's citations, already resolved to their pages
     */
    public static function fromAgent(array $answer, array $grounded = []): self
    {
        $candidates = array_map(
            DoctrineData::fromArray(...),
            array_values(array_filter(
                self::rows($answer['doctrines'] ?? null),
                static fn (array $row): bool => ! self::isPlaceholder((string) self::nullify($row['title'] ?? null)),
            )),
        );

        return new self(
            searchTerms: self::lines($answer['search_terms'] ?? null),
            doctrines: array_slice(self::distinct($candidates), 0, self::CANDIDATES),
            sources: self::records($grounded),
            pending: self::lines($answer['pending'] ?? null),
            periodicals: array_values(array_unique(array_filter(
                $grounded,
                static fn (string $url): bool => DoctrineSources::isPeriodical($url),
            ))),
        );
    }

    /**
     * The same run, with the candidates replaced by the records that confirm
     * them, and filled with the records nobody named, up to `WANTED`.
     *
     * Takes callables rather than the reader so that this class does no I/O and
     * its test does no network: DoctrineRecordReader's `read()` and
     * `articlesIn()` are what the Action passes.
     *
     * @param  callable(string): ?DoctrineData  $read
     * @param  (callable(string): list<DoctrineData>)|null  $index
     */
    public function readRecords(callable $read, ?callable $index = null): self
    {
        $pool = $this->pool($read);

        if ($index !== null) {
            $pool = $this->recover($pool, $read, $index);
        }

        $used = [];
        $found = [];
        $unconfirmed = [];

        foreach ($this->doctrines as $candidate) {
            $index = self::match($candidate, $pool, $used);

            if ($index === null) {
                $unconfirmed[] = $candidate->title;

                continue;
            }

            $used[$index] = true;
            $found[] = $pool[$index]->withRelevance($candidate->relevance);
        }

        return new self(
            searchTerms: $this->searchTerms,
            doctrines: array_slice([...$found, ...array_values(array_diff_key($pool, $used))], 0, self::WANTED),
            sources: $this->sources,
            pending: $this->pending,
            unverifiedCitations: array_values(array_unique([...$this->unverifiedCitations, ...$unconfirmed])),
            periodicals: $this->periodicals,
        );
    }

    /**
     * The pool, plus the articles the agent named that only a periodical's index
     * knew the address of.
     *
     * @param  list<DoctrineData>  $pool
     * @param  callable(string): ?DoctrineData  $read
     * @param  callable(string): list<DoctrineData>  $index
     * @return list<DoctrineData>
     */
    private function recover(array $pool, callable $read, callable $index): array
    {
        $missing = array_filter(
            $this->doctrines,
            static fn (DoctrineData $candidate): bool => self::match($candidate, $pool, []) === null,
        );

        if ($missing === []) {
            return $pool;
        }

        $entries = array_merge(...array_map(
            static fn (string $periodical): array => $index($periodical),
            array_slice($this->periodicals, 0, self::MAX_INDEXES),
        ));

        foreach ($missing as $candidate) {
            $entry = self::match($candidate, $entries, []);
            $record = $entry === null ? null : $read((string) $entries[$entry]->sourceUrl);

            if ($record !== null && self::match($record, $pool, []) === null) {
                $pool[] = $record;
            }
        }

        return $pool;
    }

    /**
     * Everything about a run except the list that has a table — the shape of
     * `legal_cases.doctrine_findings`, and the marker that the run happened.
     *
     * @return array<string, mixed>
     */
    public function findings(): array
    {
        return [
            'search_terms' => $this->searchTerms,
            'sources' => $this->sources,
            'pending' => $this->pending,
            'unverified_citations' => $this->unverifiedCitations,
            'researched_at' => CarbonImmutable::now()->toIso8601String(),
        ];
    }

    /**
     * The records that could be read: the provider's first, then the addresses
     * the agent wrote, one row per work.
     *
     * @param  callable(string): ?DoctrineData  $read
     * @return list<DoctrineData>
     */
    private function pool(callable $read): array
    {
        $addresses = self::records([
            ...$this->sources,
            ...array_map(static fn (DoctrineData $candidate): string => (string) $candidate->sourceUrl, $this->doctrines),
        ]);

        $pool = [];

        foreach (array_slice($addresses, 0, self::MAX_READS) as $url) {
            $record = $read($url);

            if ($record !== null && self::match($record, $pool, []) === null) {
                $pool[] = $record;
            }
        }

        return $pool;
    }

    /**
     * The first unused record of the pool that holds the work named, if any.
     *
     * @param  list<DoctrineData>  $pool
     * @param  array<int, true>  $used
     */
    private static function match(DoctrineData $named, array $pool, array $used): ?int
    {
        foreach ($pool as $index => $record) {
            if (! isset($used[$index]) && $named->names($record)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * One candidate per work: a model running short repeats before it invents.
     *
     * @param  list<DoctrineData>  $candidates
     * @return list<DoctrineData>
     */
    private static function distinct(array $candidates): array
    {
        $distinct = [];

        foreach ($candidates as $candidate) {
            if (self::match($candidate, $distinct, []) === null) {
                $distinct[] = $candidate;
            }
        }

        return $distinct;
    }

    /**
     * The library records of a list, deduplicated and in the order they appeared.
     *
     * @param  list<string>  $urls
     * @return list<string>
     */
    private static function records(array $urls): array
    {
        return array_values(array_unique(array_filter(
            $urls,
            static fn (string $url): bool => DoctrineSources::isRecord($url),
        )));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /**
     * The written lines of a list, without the sheet's placeholders — the
     * "## PENDENTE / nulo" that CourtDecisionResearchData documents.
     *
     * @return list<string>
     */
    private static function lines(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $lines = array_map(self::nullify(...), array_values($value));

        return array_values(array_filter(
            $lines,
            static fn (?string $line): bool => $line !== null && ! self::isPlaceholder($line),
        ));
    }

    /**
     * Whether a line is the sheet saying it had nothing to write — the empty
     * string included, which is how a missing title arrives.
     */
    private static function isPlaceholder(string $line): bool
    {
        $normalised = mb_strtolower(rtrim($line, " \t\n\r\0\x0B."));

        return in_array($normalised, [
            '',
            'nulo',
            'nenhum',
            'nada',
            'não localizado/confirmado em fonte oficial',
        ], true);
    }

    private static function nullify(mixed $value): ?string
    {
        $trimmed = trim(is_scalar($value) ? (string) $value : '');

        return $trimmed === '' ? null : $trimmed;
    }
}
