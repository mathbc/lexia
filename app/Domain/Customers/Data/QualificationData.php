<?php

declare(strict_types=1);

namespace App\Domain\Customers\Data;

use App\Domain\Customers\Enums\MaritalStatus;
use Carbon\CarbonImmutable;

/**
 * What a petição inicial states about a natural person besides their name.
 *
 * Its own object for the same reason AddressData is one: "qualificação" is a
 * real part of the document and not three loose columns, and the three fields
 * are always decided together — a company has none of them, a person may have
 * any subset.
 *
 * Every field is nullable and none is required. A registration that does not
 * know the client's occupation is still a registration, and the pleading writes
 * `[profissão]` for that one line — which beats refusing to save a client
 * because the lawyer has not asked them yet.
 */
final readonly class QualificationData
{
    public function __construct(
        public ?MaritalStatus $maritalStatus = null,
        public ?string $occupation = null,
        public ?CarbonImmutable $birthDate = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            maritalStatus: self::optionalMaritalStatus($validated['marital_status'] ?? null),
            occupation: self::optionalText($validated['occupation'] ?? null),
            birthDate: self::optionalDate($validated['birth_date'] ?? null),
        );
    }

    /**
     * Nothing to state — a company, or a person nobody has qualified yet.
     *
     * Named rather than left to `new self()` at the call site because the empty
     * qualification is a decision in CustomerData, not a default.
     */
    public static function unknown(): self
    {
        return new self;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        // Nulls are meaningful and must survive: clearing a field, or promoting
        // a client to Pessoa Jurídica, writes them over what was there.
        return [
            'marital_status' => $this->maritalStatus,
            'occupation' => $this->occupation,
            'birth_date' => $this->birthDate,
        ];
    }

    private static function optionalMaritalStatus(mixed $value): ?MaritalStatus
    {
        return blank($value) ? null : MaritalStatus::from((string) $value);
    }

    private static function optionalText(mixed $value): ?string
    {
        return blank($value) ? null : trim((string) $value);
    }

    private static function optionalDate(mixed $value): ?CarbonImmutable
    {
        return blank($value) ? null : CarbonImmutable::parse((string) $value);
    }
}
