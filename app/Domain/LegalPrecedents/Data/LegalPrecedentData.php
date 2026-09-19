<?php

declare(strict_types=1);

namespace App\Domain\LegalPrecedents\Data;

use App\Domain\LegalPrecedents\Enums\LegalPrecedentType;

/**
 * One ruling found to sustain what the pleading argues.
 *
 * `id` is a hint and never a key, exactly as on `LegalThesisData`. `thesisId` is
 * a hint too, and that is the less obvious half: a posted `legal_thesis_id` is a
 * uuid like any other, and the database would accept one belonging to another
 * account's thesis without complaint, because a foreign key checks existence and
 * not ownership. Resolving it is SaveLegalCaseForensicReview's job, against the
 * map of theses it has just written.
 *
 * That resolution is why `toArray()` **takes the foreign key as an argument**
 * instead of reading `$this->thesisId`. The signature is the guard: there is no
 * way to produce the row to be written without having first decided what the
 * thesis resolves to, and "tidying" the parameter away into a property read
 * would reopen the cross-tenant hole silently.
 *
 * `adherence` leaves as a decimal string ("93.00"), the shape the column and the
 * cast both speak, and null means unmeasured rather than zero — the same
 * distinction `requirements.amount` makes.
 */
final readonly class LegalPrecedentData
{
    public function __construct(
        public ?string $id,
        public ?string $thesisId,
        public string $name,
        public ?LegalPrecedentType $type,
        public string $description,
        public ?string $citation,
        public ?string $grounding,
        public ?string $adherence,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: self::text($row, 'id'),
            thesisId: self::text($row, 'legal_thesis_id'),
            name: (string) self::text($row, 'name'),
            type: LegalPrecedentType::tryFrom((string) self::text($row, 'type')),
            description: (string) self::text($row, 'description'),
            citation: self::text($row, 'citation'),
            grounding: self::text($row, 'grounding'),
            adherence: self::percent($row['adherence'] ?? null),
        );
    }

    /**
     * What gets written to the row.
     *
     * The id is not part of it, and the thesis is whatever the caller resolved —
     * see the class docblock for why it arrives as a parameter.
     *
     * @return array<string, mixed>
     */
    public function toArray(?string $thesisId): array
    {
        return [
            'legal_thesis_id' => $thesisId,
            'name' => $this->name,
            'type' => $this->type,
            'description' => $this->description,
            'citation' => $this->citation,
            'grounding' => $this->grounding,
            'adherence' => $this->adherence,
        ];
    }

    /**
     * A precedent with no name is not a precedent.
     */
    public function isWritten(): bool
    {
        return $this->name !== '';
    }

    /**
     * The adherence as the column writes it, or null if none was measured.
     *
     * Reads "93", "93%", "93,5%" and 93.5 alike — the separator is found rather
     * than assumed, the same way `RequirementData` reads money, because a model
     * trained on Brazilian text writes the comma often enough to matter. Above
     * a hundred is clamped rather than refused: losing the whole finding over an
     * enthusiastic 120 would be the worse trade.
     *
     * Zero is read as no measurement at all, mirroring "a request for nothing is
     * a request with no cifra": a ruling scored zero would not have been put in
     * the list.
     *
     * A model answering on the 0-1 scale is *not* rescaled. "0.93" is stored as
     * 0,93% and reads as the obvious nonsense it is, because multiplying it by a
     * hundred would be a guess, and the same guess would turn a genuine 0,93%
     * into 93%. The schema is where that dialect gets fixed.
     */
    private static function percent(mixed $written): ?string
    {
        $raw = str_replace(',', '.', (string) self::nullify($written));

        if (preg_match('/\d+(?:\.\d+)?/', $raw, $matches) !== 1) {
            return null;
        }

        $percent = min(100.0, (float) $matches[0]);

        return $percent === 0.0 ? null : sprintf('%.2f', $percent);
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
