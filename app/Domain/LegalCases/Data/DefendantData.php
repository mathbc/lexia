<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Data;

use App\Domain\Accounts\Enums\BrazilianState;

/**
 * The other party, as much of it as the lawyer knows.
 *
 * Every property is nullable, and that is the shape of the problem rather than
 * laxity on the way in: a client is registered once fully known, a defendant is
 * frequently a name and a licence plate. `notes` is where the rest goes.
 *
 * Deliberately not built out of AddressData, which the account and the client
 * both use. That class types the postal code, the street and the state as
 * required — correct for an entity you register, wrong for one you describe —
 * and bending it to serve both would need an all-nullable twin and a prefix
 * parameter on every key. The frontend pays this same bridge explicitly, in
 * `defendant-form-fields.tsx`, and says why.
 *
 * The masks are stripped here, so the column only ever holds one
 * representation: digits for the document, the phone and the postal code.
 */
final readonly class DefendantData
{
    public function __construct(
        public ?string $name,
        public ?string $document,
        public ?string $email,
        public ?string $phone,
        public ?string $postalCode,
        public ?string $street,
        public ?string $number,
        public ?string $complement,
        public ?string $district,
        public ?string $city,
        public ?BrazilianState $state,
        public ?string $notes,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            name: self::text($validated, 'defendant_name'),
            document: self::digits($validated, 'defendant_document'),
            email: self::email($validated),
            phone: self::digits($validated, 'defendant_phone'),
            postalCode: self::digits($validated, 'defendant_postal_code'),
            street: self::text($validated, 'defendant_street'),
            number: self::text($validated, 'defendant_number'),
            complement: self::text($validated, 'defendant_complement'),
            district: self::text($validated, 'defendant_district'),
            city: self::text($validated, 'defendant_city'),
            state: BrazilianState::tryFrom((string) self::text($validated, 'defendant_state')),
            notes: self::text($validated, 'defendant_notes'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'defendant_name' => $this->name,
            'defendant_document' => $this->document,
            'defendant_email' => $this->email,
            'defendant_phone' => $this->phone,
            'defendant_postal_code' => $this->postalCode,
            'defendant_street' => $this->street,
            'defendant_number' => $this->number,
            'defendant_complement' => $this->complement,
            'defendant_district' => $this->district,
            'defendant_city' => $this->city,
            'defendant_state' => $this->state,
            'defendant_notes' => $this->notes,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private static function text(array $validated, string $key): ?string
    {
        $value = $validated[$key] ?? null;
        $trimmed = trim(is_string($value) ? $value : '');

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private static function digits(array $validated, string $key): ?string
    {
        $digits = preg_replace('/\D/', '', (string) self::text($validated, $key));

        return $digits === '' || $digits === null ? null : $digits;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private static function email(array $validated): ?string
    {
        $email = self::text($validated, 'defendant_email');

        return $email === null ? null : strtolower($email);
    }
}
