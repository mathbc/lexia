<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Data;

/**
 * Who the pleading is for, under what heading, and to whom it is addressed.
 *
 * The first step of the form: the client, the CNJ pair, and the line the
 * document opens with. Everything here is chosen rather than written, save the
 * addressing.
 *
 * The practice area arrives as a **slug** and leaves as a uuid, which is why
 * `fromArray` takes it as a second argument instead of reading it out of the
 * request. The form works in slugs on purpose — LegalCaseOptions explains it:
 * the uuid is generated on load and differs between databases — and resolving
 * one to the other needs the database, which a value object has no business
 * touching. The Action that already loaded the area to validate the class pair
 * hands it over.
 */
final readonly class LegalCaseBasicsData
{
    public function __construct(
        public string $customerId,
        public string $practiceAreaId,
        public string $proceduralClassId,
        public ?string $courtAddressing,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated, string $practiceAreaId): self
    {
        $addressing = $validated['court_addressing'] ?? null;

        return new self(
            customerId: (string) $validated['customer_id'],
            practiceAreaId: $practiceAreaId,
            proceduralClassId: (string) $validated['procedural_class_id'],
            // Already null rather than '' by the time it gets here —
            // ConvertEmptyStringsToNull is in the global stack — but trimmed
            // again because a space is not an addressing either.
            courtAddressing: self::nullify(is_string($addressing) ? $addressing : null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'customer_id' => $this->customerId,
            'practice_area_id' => $this->practiceAreaId,
            'procedural_class_id' => $this->proceduralClassId,
            'court_addressing' => $this->courtAddressing,
        ];
    }

    private static function nullify(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
