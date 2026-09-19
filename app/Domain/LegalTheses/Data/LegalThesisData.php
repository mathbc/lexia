<?php

declare(strict_types=1);

namespace App\Domain\LegalTheses\Data;

use App\Domain\LegalTheses\Enums\LegalThesisType;

/**
 * One line of argument the pleading will carry.
 *
 * `id` is what the browser sent, and is a hint rather than a key — the same rule
 * `RequirementData` states and for the same reason: the form mints its own uuids
 * with `crypto.randomUUID()` and they are v4 exactly like the server's, so
 * nothing here may treat the id as proof of anything. It is carried so the
 * saving Action can look it up among the rows the pleading actually owns, and
 * `toArray()` deliberately leaves it out.
 *
 * It is carried for a second reason here, which requirements do not have: a
 * precedent quotes it to say which thesis it sustains. The resolution of that
 * quote is SaveLegalCaseForensicReview's id map, never a lookup in the database.
 *
 * `type` is nullable because the step saves partially and a thesis whose kind is
 * still undecided is a real state; `description` is not, for the same reason a
 * requirement's is not — a thesis that argues nothing is not a thesis.
 */
final readonly class LegalThesisData
{
    /**
     * @param  list<LegalBasisData>  $legalBases
     */
    public function __construct(
        public ?string $id,
        public string $name,
        public ?LegalThesisType $type,
        public string $description,
        public ?string $impact,
        public array $legalBases,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: self::text($row, 'id'),
            name: (string) self::text($row, 'name'),
            type: LegalThesisType::tryFrom((string) self::text($row, 'type')),
            description: (string) self::text($row, 'description'),
            impact: self::text($row, 'impact'),
            legalBases: LegalBasisData::listFrom($row['legal_bases'] ?? null),
        );
    }

    /**
     * What gets written to the row — the id is not part of it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'description' => $this->description,
            'impact' => $this->impact,
            'legal_bases' => array_map(
                static fn (LegalBasisData $basis): array => $basis->toArray(),
                $this->legalBases,
            ),
        ];
    }

    /**
     * A thesis with no heading is not a thesis.
     *
     * The name and not the description, because the name is the first field the
     * screen draws: a row the lawyer opened and abandoned has neither, and a row
     * being written has a heading before it has an argument.
     */
    public function isWritten(): bool
    {
        return $this->name !== '';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function text(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        $trimmed = trim(is_string($value) ? $value : '');

        return $trimmed === '' ? null : $trimmed;
    }
}
