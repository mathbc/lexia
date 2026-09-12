<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Data;

use App\Domain\Accounts\Enums\BrazilianState;

/**
 * A Brazilian postal address.
 *
 * Kept as its own object because the shape is fixed by the Correios and will
 * be reused by any future addressable entity.
 */
final readonly class AddressData
{
    public function __construct(
        public string $postalCode,
        public string $street,
        public string $number,
        public ?string $complement,
        public string $district,
        public string $city,
        public BrazilianState $state,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            postalCode: self::digitsOnly((string) $validated['postal_code']),
            street: (string) $validated['street'],
            number: (string) $validated['number'],
            complement: $validated['complement'] ?? null,
            district: (string) $validated['district'],
            city: (string) $validated['city'],
            state: BrazilianState::from((string) $validated['state']),
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'postal_code' => $this->postalCode,
            'street' => $this->street,
            'number' => $this->number,
            'complement' => $this->complement,
            'district' => $this->district,
            'city' => $this->city,
            'state' => $this->state->value,
        ];
    }

    private static function digitsOnly(string $value): string
    {
        return (string) preg_replace('/\D/', '', $value);
    }
}
