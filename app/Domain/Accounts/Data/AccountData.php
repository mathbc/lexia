<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Data;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Enums\BrazilianState;

/**
 * Everything needed to create or update an account.
 *
 * Identifiers arrive masked from the UI and are normalised to digits here, so
 * the database only ever stores one representation.
 */
final readonly class AccountData
{
    public function __construct(
        public string $name,
        public ?string $legalName,
        public AccountType $type,
        public ?string $federalId,
        public ?string $oabNumber,
        public ?BrazilianState $oabState,
        public string $email,
        public string $phone,
        public AddressData $address,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        $type = AccountType::from((string) $validated['type']);

        return new self(
            name: (string) $validated['name'],
            legalName: $type->requiresLegalName() ? (string) $validated['legal_name'] : null,
            type: $type,
            federalId: $type->requiresFederalId()
                ? self::digitsOnly((string) $validated['federal_id'])
                : null,
            oabNumber: $type->requiresOab()
                ? strtoupper(trim((string) $validated['oab_number']))
                : null,
            oabState: $type->requiresOab()
                ? BrazilianState::from((string) $validated['oab_state'])
                : null,
            email: strtolower(trim((string) $validated['email'])),
            phone: self::digitsOnly((string) $validated['phone']),
            address: AddressData::fromArray($validated),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'legal_name' => $this->legalName,
            'type' => $this->type,
            'federal_id' => $this->federalId,
            'oab_number' => $this->oabNumber,
            'oab_state' => $this->oabState,
            'email' => $this->email,
            'phone' => $this->phone,
            ...$this->address->toArray(),
        ];
    }

    private static function digitsOnly(string $value): string
    {
        return (string) preg_replace('/\D/', '', $value);
    }
}
