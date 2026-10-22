<?php

declare(strict_types=1);

namespace App\Domain\Doctrines\Data;

use Illuminate\Support\Str;

/**
 * One work of legal scholarship, as the LexML catalogues it.
 *
 * The fields are the record — Tipo, Autor, Título, Data, Ementa, Assuntos —
 * plus `publication`, which DoctrineRecordReader composes from the record's
 * "Publicação" block, and `relevance`, the research agent's note. See the
 * `doctrines` migration for the mapping and for why `publishedIn` stays text.
 *
 * ## The guard is the author
 *
 * CourtDecisionData asks for a title and an ementa, because a ruling is cited
 * for its ementa. A work of doctrine is cited **for who wrote it**: the
 * reference a pleading prints is "AUTHOR. Title. Where.", and a work without
 * an author is either the periodical itself or a record the library left
 * incomplete. Neither can be attributed, and attributing an understanding to
 * nobody in particular is the one thing doctrine in a pleading must never do.
 * So `isAttributable()` asks for both, and the summary is optional — most
 * records have none, and refusing them would refuse most of the catalogue.
 */
final readonly class DoctrineData
{
    public function __construct(
        public ?string $id,
        public ?string $kind,
        public string $author,
        public string $title,
        public ?string $publishedIn,
        public ?string $summary,
        public ?string $subject,
        public ?string $publication,
        public ?string $relevance,
        public ?string $sourceUrl,
        public ?string $urn,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: self::text($row, 'id'),
            kind: self::text($row, 'kind'),
            author: (string) self::text($row, 'author'),
            title: (string) self::text($row, 'title'),
            publishedIn: self::text($row, 'published_in'),
            summary: self::text($row, 'summary'),
            subject: self::text($row, 'subject'),
            publication: self::text($row, 'publication'),
            relevance: self::text($row, 'relevance'),
            sourceUrl: self::text($row, 'source_url'),
            urn: self::text($row, 'urn'),
        );
    }

    /**
     * What gets written to the row — never the id, which is a hint.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'author' => $this->author,
            'title' => $this->title,
            'published_in' => $this->publishedIn,
            'summary' => $this->summary,
            'subject' => $this->subject,
            'publication' => $this->publication,
            'relevance' => $this->relevance,
            'source_url' => $this->sourceUrl,
            'urn' => $this->urn,
        ];
    }

    /**
     * The same record, with the research agent's note beside it.
     *
     * The page has no opinion about this case, so the one field it cannot fill
     * is grafted on from the candidate — see DoctrineResearchData::readRecords().
     */
    public function withRelevance(?string $relevance): self
    {
        return new self(
            id: $this->id,
            kind: $this->kind,
            author: $this->author,
            title: $this->title,
            publishedIn: $this->publishedIn,
            summary: $this->summary,
            subject: $this->subject,
            publication: $this->publication,
            relevance: $relevance,
            sourceUrl: $this->sourceUrl,
            urn: $this->urn,
        );
    }

    /**
     * A work with no title or no author cannot be cited — see the class docblock.
     */
    public function isAttributable(): bool
    {
        return $this->title !== '' && $this->author !== '';
    }

    /**
     * Whether this — what the research agent named — is the work a record holds.
     *
     * The match DoctrineResearchData relies on, because the agent's address
     * cannot be trusted (GroundingRedirect has the measurement) and its title
     * and author can be checked. Titles match whole, or by the main title when
     * one side carries a subtitle ("Responsabilidade civil: doutrina e
     * jurisprudência"). Authors match when a surname-sized word the agent wrote
     * appears in the catalogue's heading — which survives "Sergio Cavalieri
     * Filho" against "Cavalieri Filho, Sergio, 1949", and keeps two books
     * called "Responsabilidade civil" apart. An agent that wrote no author is
     * matched by the title alone.
     */
    public function names(self $record): bool
    {
        return self::sameTitle($this->title, $record->title)
            && self::sameAuthor($this->author, $record->author);
    }

    private static function sameTitle(string $named, string $recorded): bool
    {
        $whole = self::normalise($named);

        if ($whole === '') {
            return false;
        }

        return $whole === self::normalise($recorded)
            || self::normalise(self::mainTitle($named)) === self::normalise(self::mainTitle($recorded));
    }

    private static function sameAuthor(string $named, string $recorded): bool
    {
        $words = array_filter(
            explode(' ', self::normalise($named)),
            static fn (string $word): bool => mb_strlen($word) >= 4,
        );

        if ($words === []) {
            return true;
        }

        $catalogued = explode(' ', self::normalise($recorded));

        return array_intersect($words, $catalogued) !== [];
    }

    private static function mainTitle(string $title): string
    {
        return explode(':', $title, 2)[0];
    }

    /**
     * Lowercase ASCII words: accents, punctuation and spacing are what a model
     * and a catalogue spell differently.
     */
    private static function normalise(string $text): string
    {
        $ascii = strtolower(Str::ascii($text));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $ascii));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function text(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        $trimmed = trim(is_scalar($value) ? (string) $value : '');

        return $trimmed === '' ? null : $trimmed;
    }
}
