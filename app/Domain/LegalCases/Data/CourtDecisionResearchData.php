<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Data;

use App\Domain\CourtDecisions\Data\CourtDecisionData;
use App\Domain\CourtDecisions\Support\CourtDecisionSources;
use Carbon\CarbonImmutable;

/**
 * What a jurisprudence run found in the LexML, and what it had to throw away.
 *
 * The sibling of LegalResearchData, and built on the same two layers: a list
 * that could become rows, and the *account of the research* around it — the
 * question that was asked, the records that were opened, what stayed
 * unresolved, and which citations the guard refused. Only the first half has a
 * table; the rest describes one run rather than the pleading.
 *
 * ## The guard, and why it lives here
 *
 * Every decision the agent returns carries a `source_url`, and every one is
 * checked against CourtDecisionSources — not merely that the host is the LexML,
 * but that the address is a `/urn/` record. A decision whose url is missing,
 * unparseable, served by something else, or pointing at the portal's front page
 * is **removed** and its title recorded in `unverifiedCitations`.
 *
 * The stricter test is what this envelope adds to its sibling, and the reason
 * is the catalogue's own shape. In step six a court's home page is a plausible
 * thing for a model to cite and a useless one; here the equivalent mistake is
 * citing `lexml.gov.br` itself, or a `/busca/` url — which is worse than
 * useless, because `/busca/` is `Disallow`ed in the portal's robots.txt and
 * answers a client with the Senado's anti-bot challenge. A lawyer clicking it
 * would get a page of JavaScript, not a ruling.
 *
 * It removes rather than rewrites, and it reports rather than deletes in
 * silence — the pair of decisions every guard in this project makes. A lawyer
 * looking at an empty list needs to know whether the research found nothing or
 * whether the guard refused what it found: those are opposite situations and
 * they look identical once the list is empty.
 *
 * ## The two ceilings, and why only one of them is enforced
 *
 * `MAX_DECISIONS` is a ceiling and is applied here, like every ceiling in this
 * codebase. `MIN_DECISIONS` is **not** a floor, and the asymmetry is
 * deliberate: it is what the prompt asks for and what the agent test holds the
 * agent to, but nothing here invents a third ruling to satisfy it. A run that
 * opened the LexML and confirmed two is a legitimate and expensive answer;
 * padding it would be the one failure this whole design refuses.
 */
final readonly class CourtDecisionResearchData
{
    /**
     * What the prompt asks for, and what the agent test holds the agent to.
     *
     * Three is the number the screen already promises — "o agente de IA buscará
     * três jurisprudências". It is a request to the model, never a guarantee
     * from this class: see the class docblock.
     */
    public const MIN_DECISIONS = 3;

    /**
     * What the grammar imposes.
     *
     * Above this a model stops finding rulings and starts restating one — the
     * brake RequirementExtractionAgent puts on requests, for the same failure.
     */
    public const MAX_DECISIONS = 6;

    /**
     * @param  list<CourtDecisionData>  $decisions
     * @param  list<string>  $sources  the LexML records the run actually opened
     * @param  list<string>  $pending  what the research could not resolve
     * @param  list<string>  $unverifiedCitations  decisions dropped for want of a record
     */
    public function __construct(
        public ?string $legalQuestion,
        public array $decisions,
        public array $sources = [],
        public array $pending = [],
        public array $unverifiedCitations = [],
    ) {}

    /**
     * The transcription agent's answer, checked and cut to size.
     *
     * `$consulted` is the list of urls the *provider* reported having opened,
     * read off the grounding metadata rather than out of the model's answer,
     * which is what makes it the one part of this payload nothing can
     * hallucinate. It is merged with what the agent claims and then filtered,
     * so a record only the provider saw is still credited and one the model
     * merely named is not.
     *
     * @param  array<string, mixed>  $answer
     * @param  list<string>  $consulted  urls the provider reported opening
     */
    public static function fromAgent(array $answer, array $consulted = []): self
    {
        $rows = self::rows($answer['decisions'] ?? null);

        $confirmed = array_slice(
            self::distinct(array_values(array_filter($rows, self::isRecord(...)))),
            0,
            self::MAX_DECISIONS,
        );

        return new self(
            legalQuestion: self::nullify($answer['legal_question'] ?? null),
            decisions: array_map(CourtDecisionData::fromArray(...), $confirmed),
            sources: self::records([
                ...self::lines($answer['sources_consulted'] ?? null),
                ...$consulted,
            ]),
            pending: self::lines($answer['pending'] ?? null),
            unverifiedCitations: self::refused($rows),
        );
    }

    /**
     * The same run, with every ruling replaced by the record the portal serves.
     *
     * The step that makes the ementa the court's text rather than the model's
     * recollection, and it is not an enrichment — it is the transcription. What
     * `fromAgent()` produced are *candidates*: an address the model vouched
     * for, and whatever it managed to say around it. What comes back from here
     * is what the Senado published at that address.
     *
     * It exists as a separate step, taking a callable rather than a reader,
     * because this class does no I/O and its test does no network.
     * LexmlRecordReader is what the Action passes; a closure returning fixtures
     * is what the test passes, and neither knows about the other.
     *
     * A ruling whose record cannot be read is **dropped and reported**, joining
     * the citations the guard refused — same list, because from the lawyer's
     * side they are the same event: the agent named a ruling and nothing
     * confirmed it. The reasons differ (an invented address, a portal timing
     * out, a page whose labels moved) and none of them makes a citation safe to
     * print.
     *
     * `subject` is the one field carried over from the candidate instead of
     * taken from the page, and the exception is the catalogue's doing: the
     * Assuntos row is printed on the search screen — which robots.txt closes —
     * and not on the record. So the index terms are the model's reading, the
     * rest is the portal's text, and a page that did print them would win.
     *
     * @param  callable(string): ?CourtDecisionData  $read
     */
    public function readRecords(callable $read): self
    {
        $records = [];
        $unreadable = [];

        foreach ($this->decisions as $candidate) {
            $record = $candidate->sourceUrl === null ? null : $read($candidate->sourceUrl);

            if ($record === null) {
                $unreadable[] = $candidate->title === '' ? 'Julgado sem identificação' : $candidate->title;

                continue;
            }

            $records[] = $record->subject === null ? $record->withSubject($candidate->subject) : $record;
        }

        return new self(
            legalQuestion: $this->legalQuestion,
            decisions: $records,
            sources: $this->sources,
            pending: $this->pending,
            unverifiedCitations: array_values(array_unique([...$this->unverifiedCitations, ...$unreadable])),
        );
    }

    /**
     * Everything about a run except the list that has a table.
     *
     * The shape a `legal_cases` column will hold the day the screen persists
     * this, mirroring `LegalResearchData::findings()` — including
     * `researched_at`, which cannot be `updated_at` because every step of the
     * wizard touches the pleading, and which is the marker that distinguishes
     * "researched, found nothing" from "never researched". That distinction is
     * what keeps the screen from paying for the same answer twice.
     *
     * @return array<string, mixed>
     */
    public function findings(): array
    {
        return [
            'legal_question' => $this->legalQuestion,
            'sources' => $this->sources,
            'pending' => $this->pending,
            'unverified_citations' => $this->unverifiedCitations,
            'researched_at' => CarbonImmutable::now()->toIso8601String(),
        ];
    }

    /**
     * What the screen reads.
     *
     * `id` is published as null on every row, as the precedents are: the key of
     * a new decision is minted by the database, never by the agent.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'legal_question' => $this->legalQuestion,
            'decisions' => array_map(
                static fn (CourtDecisionData $decision): array => ['id' => null] + $decision->toArray(),
                $this->decisions,
            ),
            'sources' => $this->sources,
            'pending' => $this->pending,
            'unverified_citations' => $this->unverifiedCitations,
        ];
    }

    /**
     * Whether the guard refused anything this run.
     */
    public function hasUnverifiedCitations(): bool
    {
        return $this->unverifiedCitations !== [];
    }

    /**
     * One row per address.
     *
     * Three copies of the same acórdão are not three options, and a model
     * running short of genuine answers repeats before it invents — the failure
     * the ceiling on requests already anticipates. Deduplicating here rather
     * than asking the prompt for variety means the count the screen shows is a
     * count of rulings.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function distinct(array $rows): array
    {
        $seen = [];
        $distinct = [];

        foreach ($rows as $row) {
            $url = strtolower((string) self::nullify($row['source_url'] ?? null));

            if (! isset($seen[$url])) {
                $seen[$url] = true;
                $distinct[] = $row;
            }
        }

        return $distinct;
    }

    /**
     * Whether one row names the LexML record it was read from.
     *
     * @param  array<string, mixed>  $row
     */
    private static function isRecord(array $row): bool
    {
        return CourtDecisionSources::isRecord(self::nullify($row['source_url'] ?? null));
    }

    /**
     * Every decision the guard removed, named the way a reader would name it.
     *
     * By its `title`, which is the string the screen shows. A row with none is
     * reported as the reason it was refused, which is still more useful than a
     * blank line in the list.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private static function refused(array $rows): array
    {
        $refused = array_map(
            static fn (array $row): ?string => self::isRecord($row)
                ? null
                : (self::nullify($row['title'] ?? null) ?? 'Julgado sem identificação'),
            $rows,
        );

        return array_values(array_unique(array_filter(
            $refused,
            static fn (?string $citation): bool => $citation !== null,
        )));
    }

    /**
     * The LexML records of a list, deduplicated and in the order they appeared.
     *
     * @param  list<string>  $urls
     * @return list<string>
     */
    private static function records(array $urls): array
    {
        return array_values(array_unique(array_filter(
            $urls,
            static fn (string $url): bool => CourtDecisionSources::isRecord($url),
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
     * The written lines of a list, with the sheet's own placeholders removed.
     *
     * `nulo` is what the prompt tells the researcher to write where it has
     * nothing, and the transcriber is told to turn that into a JSON null — but
     * it is a whole *section* here rather than a field, so "## PENDENTE / nulo"
     * arrives as a list containing the word. Observed on the first green run:
     * `pending: ["nulo"]`, which a screen would show the lawyer as an
     * outstanding item called "nulo".
     *
     * Dropping it here rather than sharpening either prompt is the cheaper
     * fix and the more durable one: a placeholder that leaks is a formatting
     * accident, and formatting accidents are what a Data object is for.
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
     * Whether a line is the sheet saying it had nothing to write.
     *
     * The bare `nulo`, and the sentinel the prompt fixes word for word. Both
     * are answers *about* the absence of content, never content.
     */
    private static function isPlaceholder(string $line): bool
    {
        $normalised = mb_strtolower(rtrim($line, " \t\n\r\0\x0B."));

        return in_array($normalised, [
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
