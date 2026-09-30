<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Data;

/**
 * Who the pleading is for, what happened, under what heading, and to whom it is
 * addressed — and whether it can wait.
 *
 * The first step of the form: the client, the narrative, the CNJ pair, the line
 * the document opens with, the system it will be filed through, and the
 * decision to ask for an injunction with the text that justifies it. The
 * client, the pair and the system are chosen; the addressing, the narrative
 * and the justification are written.
 *
 * The practice area arrives as a **slug** and leaves as a uuid, which is why
 * `fromArray` takes it as a second argument instead of reading it out of the
 * request. The form works in slugs on purpose — LegalCaseOptions explains it:
 * the uuid is generated on load and differs between databases — and resolving
 * one to the other needs the database, which a value object has no business
 * touching. The Action that already loaded the area to validate the class pair
 * hands it over.
 *
 * Unchecking the injunction clears its description, here and not only on the
 * screen. The form already does it and says why — "uma descrição guardada sob
 * um pedido que não existe é dado que ninguém consegue interpretar depois" —
 * and the rule belongs on this side too, because the browser is not where
 * invariants are kept.
 *
 * The suggestion is the one thing unchecking does **not** clear. It is not a
 * description of a request but the record of what the urgent-relief agent
 * said, and "the AI recommended it and the lawyer declined" is worth keeping
 * exactly as it is. It arrives as the envelope the screen received and is read
 * back through InjunctiveReliefSuggestionData, never recomputed.
 *
 * The addressing suggestion follows the same rule, beside the addressing and
 * the system it pre-filled: read back through CourtAddressingSuggestionData,
 * never recomputed, and never the source of `judicial_system_id`.
 */
final readonly class LegalCaseBasicsData
{
    public function __construct(
        public string $customerId,
        public string $practiceAreaId,
        public string $proceduralClassId,
        public ?string $judicialSystemId,
        public ?string $courtAddressing,
        public ?string $facts,
        public bool $injunctiveRelief,
        public ?string $injunctiveReliefDescription,
        public ?InjunctiveReliefSuggestionData $injunctiveReliefSuggestion = null,
        public ?CourtAddressingSuggestionData $courtAddressingSuggestion = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated, string $practiceAreaId): self
    {
        $relief = (bool) ($validated['injunctive_relief'] ?? false);

        return new self(
            customerId: (string) $validated['customer_id'],
            practiceAreaId: $practiceAreaId,
            proceduralClassId: (string) $validated['procedural_class_id'],
            judicialSystemId: self::nullify($validated['judicial_system_id'] ?? null),
            // Already null rather than '' by the time it gets here —
            // ConvertEmptyStringsToNull is in the global stack — but trimmed
            // again because a space is not an addressing, nor a narrative.
            courtAddressing: self::nullify($validated['court_addressing'] ?? null),
            facts: self::nullify($validated['facts'] ?? null),
            injunctiveRelief: $relief,
            injunctiveReliefDescription: $relief
                ? self::nullify($validated['injunctive_relief_description'] ?? null)
                : null,
            injunctiveReliefSuggestion: InjunctiveReliefSuggestionData::fromArray(
                self::envelope($validated, 'injunctive_relief_suggestion'),
            ),
            courtAddressingSuggestion: CourtAddressingSuggestionData::fromArray(
                self::envelope($validated, 'court_addressing_suggestion'),
            ),
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
            'judicial_system_id' => $this->judicialSystemId,
            'court_addressing' => $this->courtAddressing,
            'court_addressing_suggestion' => $this->courtAddressingSuggestion?->toArray(),
            'facts' => $this->facts,
            'injunctive_relief' => $this->injunctiveRelief,
            'injunctive_relief_description' => $this->injunctiveReliefDescription,
            'injunctive_relief_suggestion' => $this->injunctiveReliefSuggestion?->toArray(),
        ];
    }

    /**
     * An AI envelope as the screen sent it back, or null when it sent none.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>|null
     */
    private static function envelope(array $validated, string $key): ?array
    {
        $envelope = $validated[$key] ?? null;

        return is_array($envelope) ? $envelope : null;
    }

    private static function nullify(mixed $value): ?string
    {
        $trimmed = trim(is_string($value) ? $value : '');

        return $trimmed === '' ? null : $trimmed;
    }
}
