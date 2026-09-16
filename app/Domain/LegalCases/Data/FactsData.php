<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Data;

/**
 * What happened, and whether it can wait.
 *
 * The narrative the whole pleading is built on, plus the decision to ask for an
 * injunction and the text that justifies it.
 *
 * Unchecking the injunction clears its description, here and not only on the
 * screen. The form already does it and says why — "uma descrição guardada sob
 * um pedido que não existe é dado que ninguém consegue interpretar depois" —
 * and the rule belongs on this side too, because the browser is not where
 * invariants are kept.
 */
final readonly class FactsData
{
    public function __construct(
        public ?string $facts,
        public bool $injunctiveRelief,
        public ?string $injunctiveReliefDescription,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        $relief = (bool) ($validated['injunctive_relief'] ?? false);

        return new self(
            facts: self::nullify($validated['facts'] ?? null),
            injunctiveRelief: $relief,
            injunctiveReliefDescription: $relief
                ? self::nullify($validated['injunctive_relief_description'] ?? null)
                : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'facts' => $this->facts,
            'injunctive_relief' => $this->injunctiveRelief,
            'injunctive_relief_description' => $this->injunctiveReliefDescription,
        ];
    }

    private static function nullify(mixed $value): ?string
    {
        $trimmed = trim(is_string($value) ? $value : '');

        return $trimmed === '' ? null : $trimmed;
    }
}
