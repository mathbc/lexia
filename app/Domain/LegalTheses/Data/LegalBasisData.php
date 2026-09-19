<?php

declare(strict_types=1);

namespace App\Domain\LegalTheses\Data;

use App\Domain\LegalTheses\Enums\LegalBasisType;

/**
 * One authority a thesis rests on: an article, a statute, a súmula, an ADC, a
 * tema.
 *
 * The contract, which is the whole of this class and is not enforceable
 * anywhere else:
 *
 * - `reference` is the citation **complete and ready to read**, exactly as the
 *   chip under the thesis shows it — "Súmula 393 do STJ", "Art. 135, III, do
 *   CTN", "Lei nº 6.830/80".
 * - `source` is the authority it comes from, as a sigla — "STJ", "CTN",
 *   "CF/88" — and repeats what the reference already says, on purpose.
 *
 * The redundancy is what a screen groups and filters by, and it avoids a problem
 * composition cannot solve: the preposition is gendered in Portuguese — *do*
 * CPC but *da* CF/88 — so assembling the chip out of `type`, `reference` and
 * `source` would need the grammatical gender of every sigla in Brazilian law,
 * and would write bad Portuguese until that table was complete. `documents`
 * makes the same trade with `extension`, which repeats the filename's suffix
 * because it is read far more often than it is written.
 *
 * `type` is nullable and `reference` is not: a citation nobody could classify is
 * still a citation, and dropping it would lose fundamentação over a taxonomy.
 * An unknown string arrives as null rather than raising — the screen and a model
 * both send free text, and `tryFrom` is the whole of the decision.
 */
final readonly class LegalBasisData
{
    public function __construct(
        public ?LegalBasisType $type,
        public string $reference,
        public ?string $source,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            type: LegalBasisType::tryFrom((string) self::text($row, 'type')),
            reference: (string) self::text($row, 'reference'),
            source: self::text($row, 'source'),
        );
    }

    /**
     * What gets written into the jsonb column.
     *
     * The enum leaves as its backing value and never as the object: a stored
     * document is a historical record, in the same way a migration is, and
     * coupling it to a PHP enum that will still change is what the schema
     * already refuses to do for `type` columns.
     *
     * @return array{type: string|null, reference: string, source: string|null}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type?->value,
            'reference' => $this->reference,
            'source' => $this->source,
        ];
    }

    /**
     * A basis with no citation is not a basis.
     *
     * The mirror of `RequirementData::isWritten()`: the empty line is a field
     * that was just opened, and it must neither become an item nor block the
     * save.
     */
    public function isWritten(): bool
    {
        return $this->reference !== '';
    }

    /**
     * Every basis of a posted list, blanks dropped.
     *
     * @param  mixed  $rows  whatever arrived under the key — an array, or not
     * @return list<self>
     */
    public static function listFrom(mixed $rows): array
    {
        $bases = array_map(
            static fn (mixed $row): self => self::fromArray(is_array($row) ? $row : []),
            is_array($rows) ? array_values($rows) : [],
        );

        return array_values(array_filter(
            $bases,
            static fn (self $basis): bool => $basis->isWritten(),
        ));
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
