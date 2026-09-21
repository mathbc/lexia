<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Data;

use App\Domain\Shared\Support\OfficialLegalSources;
use Carbon\CarbonImmutable;

/**
 * What a research run found, and what it had to throw away.
 *
 * Two layers, and the split is the point. `review` is the part that can be
 * written to the database — theses and precedents in exactly the shape
 * SaveLegalCaseForensicReview accepts. Everything else is the *account of the
 * research*: the question that was asked, the portals that were opened, what
 * stayed unresolved, and which citations were refused. None of that has a
 * column, and none of it should: it describes one run, not the pleading.
 *
 * ## The guard, and why it lives here
 *
 * Every citation the agent returns carries a `source_url`, and every one is
 * checked against OfficialLegalSources. A citation whose url is missing,
 * unparseable, or served by anything other than an official portal is **removed
 * from the fundamentação** and its reference recorded in `unverifiedCitations`.
 *
 * This is not belt-and-braces. On Gemini the provider-side allowlist is
 * discarded before the request is built — `webSearchToolOptions()` returns `[]`
 * — so nothing upstream prevents the model from grounding an answer in a blog.
 * The check here is the only one there is.
 *
 * It removes rather than rewrites, and it **reports** rather than deletes in
 * silence, which is the same pair of decisions the two existing guards made and
 * for the same reasons. `RequirementListData` drops a figure the narrative does
 * not contain, because a wrong number in a money column is worse than an empty
 * one. `RefinedFactsData` reports an invented figure instead of erasing it,
 * because a sentence cannot be left half-blank. Here both apply at once: the
 * citation goes, and the fact that it went is handed to the screen. A lawyer
 * looking at a thesis with no fundamentação needs to know whether the research
 * found nothing or whether the guard refused what it found — those are opposite
 * situations and they look identical once the list is empty.
 *
 * A thesis is never dropped for failing the check. Losing the argument because
 * its supporting citation could not be confirmed would throw away the part a
 * lawyer can still use, and the thesis arrives visibly unsupported, which is a
 * state somebody fixes.
 *
 * ## The ceilings
 *
 * They live here rather than in the agents because they are a property of what
 * a pleading argues, not of who is asked. Both agents read them — `app/Ai`
 * already depends on `app/Domain` for OfficialLegalSources, and this is the
 * same direction. The researcher asks for them in words and the transcriber's
 * grammar caps only the outer list, so this is the one place they are actually
 * enforced.
 */
final readonly class LegalResearchData
{
    /**
     * More theses than a pleading argues, and fewer than a model will list.
     *
     * The brake `RequirementExtractionAgent` puts on requests, for the same
     * failure: a small ceiling stops a model from restating one argument five
     * ways once it has run out of real ones.
     */
    public const MAX_THESES = 5;

    /**
     * Per thesis, not per answer.
     */
    public const MAX_PRECEDENTS = 5;

    public const MAX_LEGAL_BASES = 8;

    /**
     * @param  list<string>  $sources  the official urls the run actually opened
     * @param  list<string>  $pending  what the research could not resolve
     * @param  list<string>  $unverifiedCitations  references dropped for want of an official source
     */
    public function __construct(
        public ?string $legalQuestion,
        public ForensicReviewData $review,
        public array $sources = [],
        public array $pending = [],
        public array $unverifiedCitations = [],
    ) {}

    /**
     * The transcription agent's answer, checked and flattened.
     *
     * `$consulted` is the list of urls the *provider* reported having opened —
     * read off the grounding metadata rather than out of the model's answer,
     * which is what makes it the one part of this payload nothing can
     * hallucinate. It is merged with what the agent claims to have consulted
     * and then filtered, so an official portal that only the provider saw is
     * still credited and a portal the model merely named is not.
     *
     * @param  array<string, mixed>  $answer
     * @param  list<string>  $consulted  urls the provider reported opening
     */
    public static function fromAgent(array $answer, array $consulted = []): self
    {
        $theses = array_slice(self::rows($answer['theses'] ?? null), 0, self::MAX_THESES);

        return new self(
            legalQuestion: self::nullify($answer['legal_question'] ?? null),
            review: ForensicReviewData::fromAgent(array_map(self::confirmed(...), $theses)),
            sources: self::officialUrls([
                ...self::lines($answer['sources_consulted'] ?? null),
                ...$consulted,
            ]),
            pending: self::lines($answer['pending'] ?? null),
            unverifiedCitations: self::refused($theses),
        );
    }

    /**
     * Everything about a run except the two lists that have tables.
     *
     * This is what goes into `legal_cases.research_findings`, and the split is
     * the same one `toArray()` makes: the theses and the precedents become
     * rows, and what surrounds them — the question, the portals opened, the
     * unresolved, the refused — becomes this. None of it has a column of its
     * own and none of it needs one; one screen reads it whole.
     *
     * `researched_at` is written here rather than derived from `updated_at`
     * because the pleading is touched by every step of the wizard, and this has
     * to say when the *research* ran.
     *
     * The value is also the marker that a run happened at all. A run that
     * confirms nothing writes no theses, so the presence of this array is the
     * only thing that distinguishes "researched, found nothing" from "never
     * researched" — which is what keeps the screen from asking again, and from
     * paying for the answer twice. The migration says the rest.
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
     * `review` is published as the two flat lists because that is what the form
     * hydrates and what the save accepts — the nesting was a device for the
     * grammar, and it ends here.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'legal_question' => $this->legalQuestion,
            'theses' => array_map(
                static fn ($thesis): array => ['id' => $thesis->id] + $thesis->toArray(),
                $this->review->theses,
            ),
            'precedents' => array_map(
                static fn ($precedent): array => ['id' => null, 'legal_thesis_id' => $precedent->thesisId]
                    + $precedent->toArray($precedent->thesisId),
                $this->review->precedents,
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
     * One thesis with every unconfirmable citation removed, and both lists cut
     * to size.
     *
     * @param  array<string, mixed>  $thesis
     * @return array<string, mixed>
     */
    private static function confirmed(array $thesis): array
    {
        $thesis['legal_bases'] = array_slice(
            self::official(self::rows($thesis['legal_bases'] ?? null)),
            0,
            self::MAX_LEGAL_BASES,
        );

        $thesis['precedents'] = array_slice(
            self::official(self::rows($thesis['precedents'] ?? null)),
            0,
            self::MAX_PRECEDENTS,
        );

        return $thesis;
    }

    /**
     * The rows whose `source_url` was served by an official portal.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function official(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => OfficialLegalSources::covers(
                self::nullify($row['source_url'] ?? null),
            ),
        ));
    }

    /**
     * Every citation the guard removed, named the way a reader would name it.
     *
     * A legal basis answers by its `reference` — "Súmula 393 do STJ" — and a
     * ruling by its `name`, because those are the strings the screen already
     * shows. A row with neither is reported as the reason it was refused, which
     * is still more useful than a blank line in the list.
     *
     * @param  list<array<string, mixed>>  $theses
     * @return list<string>
     */
    private static function refused(array $theses): array
    {
        $refused = [];

        foreach ($theses as $thesis) {
            foreach (self::rows($thesis['legal_bases'] ?? null) as $basis) {
                $refused[] = self::unconfirmed($basis, 'reference');
            }

            foreach (self::rows($thesis['precedents'] ?? null) as $precedent) {
                $refused[] = self::unconfirmed($precedent, 'name');
            }
        }

        return array_values(array_unique(array_filter(
            $refused,
            static fn (?string $citation): bool => $citation !== null,
        )));
    }

    /**
     * How one refused row is named, or null if it was not refused.
     *
     * @param  array<string, mixed>  $row
     */
    private static function unconfirmed(array $row, string $key): ?string
    {
        if (OfficialLegalSources::covers(self::nullify($row['source_url'] ?? null))) {
            return null;
        }

        return self::nullify($row[$key] ?? null) ?? 'Citação sem identificação';
    }

    /**
     * The official urls of a list, deduplicated and in the order they appeared.
     *
     * @param  list<string>  $urls
     * @return list<string>
     */
    private static function officialUrls(array $urls): array
    {
        return array_values(array_unique(array_filter(
            $urls,
            static fn (string $url): bool => OfficialLegalSources::covers($url),
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
            static fn (?string $line): bool => $line !== null,
        ));
    }

    private static function nullify(mixed $value): ?string
    {
        $trimmed = trim(is_scalar($value) ? (string) $value : '');

        return $trimmed === '' ? null : $trimmed;
    }
}
