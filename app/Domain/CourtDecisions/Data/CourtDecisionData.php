<?php

declare(strict_types=1);

namespace App\Domain\CourtDecisions\Data;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * One ruling the courts have already handed down in a case like this one.
 *
 * The eight fields are the LexML record, and the mapping is deliberate rather
 * than invented: a page under `/urn/` prints Localidade, Autoridade, Título,
 * Data, Ementa, Assuntos and the Nome Uniforme, and those are `locality`,
 * `authority`, `title`, `decidedAt`, `summary`, `subject` and `urn`. Copying a
 * catalogue's own vocabulary is what keeps a transcription checkable by eye:
 * the dump beside the page should read the same.
 *
 * ## Why this is not a LegalPrecedentData
 *
 * The two look alike and answer different questions. A precedent is a *finding
 * about this case*: it carries `adherence` and `grounding`, which say how well
 * the ruling fits these facts and what it does for them, and it hangs off the
 * thesis it sustains. A court decision is the **document**: what the tribunal
 * decided, transcribed, with no claim attached about this pleading. Hence no
 * score and no thesis — the reading is the lawyer's to make.
 *
 * `sourceUrl` is the record that was read, and it is the only field the guard
 * looks at. Without it a decision cannot be checked, and an unverifiable ruling
 * is the exact failure the whole prompt exists to prevent — a fabricated ementa
 * is shaped like a real one.
 *
 * `decidedAt` is a date and not a string because the column is one, and the
 * portal writes `24/03/2010` while a model asked for a date writes
 * `2010-03-24` about half the time. Reading both is cheaper than insisting on
 * either, and an unreadable date is null rather than a raised exception: losing
 * the whole ruling over its date would be the worse trade, which is the call
 * `LegalPrecedentData::percent()` already makes for an enthusiastic 120%.
 */
final readonly class CourtDecisionData
{
    public function __construct(
        public ?string $id,
        public string $title,
        public ?string $locality,
        public ?string $authority,
        public string $summary,
        public ?string $subject,
        public ?string $sourceUrl,
        public ?string $urn,
        public ?CarbonImmutable $decidedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: self::text($row, 'id'),
            title: (string) self::text($row, 'title'),
            locality: self::text($row, 'locality'),
            authority: self::text($row, 'authority'),
            summary: (string) self::text($row, 'summary'),
            subject: self::text($row, 'subject'),
            sourceUrl: self::text($row, 'source_url'),
            urn: self::text($row, 'urn'),
            decidedAt: self::date($row['decided_at'] ?? null),
        );
    }

    /**
     * What gets written to the row.
     *
     * The id is not part of it, as on every sibling Data object: a posted id is
     * a hint about which row is meant, never the key that is written.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'locality' => $this->locality,
            'authority' => $this->authority,
            'summary' => $this->summary,
            'subject' => $this->subject,
            'source_url' => $this->sourceUrl,
            'urn' => $this->urn,
            'decided_at' => $this->decidedAt?->toDateString(),
        ];
    }

    /**
     * The same record, with the index terms the page did not carry.
     *
     * The one field LexmlRecordReader cannot fill, and the reason is the
     * catalogue's: Assuntos is printed on the search screen, which robots.txt
     * closes, and not on the record itself. So it arrives from the agent, which
     * read it where the index did, and is grafted onto the page's own text
     * here. `readRecords()` in CourtDecisionResearchData is the only caller,
     * and it only calls when the page left the field empty — a page that does
     * print Assuntos wins.
     */
    public function withSubject(?string $subject): self
    {
        return new self(
            id: $this->id,
            title: $this->title,
            locality: $this->locality,
            authority: $this->authority,
            summary: $this->summary,
            subject: $subject,
            sourceUrl: $this->sourceUrl,
            urn: $this->urn,
            decidedAt: $this->decidedAt,
        );
    }

    /**
     * A decision with no title or no ementa is not a decision.
     *
     * Stricter than the sibling guards, which ask only for a name, and the
     * extra condition earns its place: a ruling is cited *for its ementa*, so a
     * row with a título and nothing under it is a reference the lawyer cannot
     * use and cannot tell apart from a complete one at a glance.
     */
    public function isWritten(): bool
    {
        return $this->title !== '' && $this->summary !== '';
    }

    /**
     * The date as the portal or the model wrote it, or null.
     *
     * `dd/mm/aaaa` first, because that is what the LexML page prints and
     * `CarbonImmutable::parse()` would read it as the American order. Anything
     * else goes to the general parser, and anything it refuses is null.
     */
    private static function date(mixed $value): ?CarbonImmutable
    {
        $written = self::nullify($value);

        if ($written === null) {
            return null;
        }

        try {
            if (preg_match('#^\d{2}/\d{2}/\d{4}$#', $written) === 1) {
                return CarbonImmutable::createFromFormat('!d/m/Y', $written) ?: null;
            }

            return CarbonImmutable::parse($written)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function text(array $row, string $key): ?string
    {
        return self::nullify($row[$key] ?? null);
    }

    private static function nullify(mixed $value): ?string
    {
        $trimmed = trim(is_scalar($value) ? (string) $value : '');

        return $trimmed === '' ? null : $trimmed;
    }
}
