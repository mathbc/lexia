<?php

declare(strict_types=1);

namespace App\Domain\Customers\Data;

use App\Domain\Accounts\Data\AddressData;
use App\Domain\Customers\Enums\CustomerType;

/**
 * Everything needed to create or update a client.
 *
 * The documents arrive masked from the UI and are normalised to digits here,
 * so the database only ever stores one representation. Only the document the
 * type calls for survives: switching a client from company to person clears
 * the CNPJ instead of leaving a stale one behind.
 *
 * The qualification follows that same rule from the other side. It describes a
 * natural person — civil status, occupation, date of birth — so promoting a
 * client to Pessoa Jurídica clears it rather than leaving a marital status
 * hanging off a company. Unlike the CPF it is optional even for a person, and
 * QualificationData says why.
 */
final readonly class CustomerData
{
    public function __construct(
        public string $name,
        public ?string $legalName,
        public CustomerType $type,
        public ?string $cpf,
        public ?string $cnpj,
        public QualificationData $qualification,
        public string $email,
        public string $phone,
        public AddressData $address,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        $type = CustomerType::from((string) $validated['type']);

        return new self(
            name: (string) $validated['name'],
            legalName: $type->requiresLegalName() ? (string) $validated['legal_name'] : null,
            type: $type,
            cpf: $type->requiresCpf() ? self::digitsOnly((string) $validated['cpf']) : null,
            cnpj: $type->requiresCnpj() ? self::digitsOnly((string) $validated['cnpj']) : null,
            qualification: $type->requiresCpf()
                ? QualificationData::fromArray($validated)
                : QualificationData::unknown(),
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
            'cpf' => $this->cpf,
            'cnpj' => $this->cnpj,
            ...$this->qualification->toArray(),
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
