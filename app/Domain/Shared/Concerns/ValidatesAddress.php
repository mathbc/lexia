<?php

declare(strict_types=1);

namespace App\Domain\Shared\Concerns;

use App\Domain\Accounts\Enums\BrazilianState;
use Illuminate\Validation\Rule;

/**
 * Validation for a Brazilian postal address.
 *
 * The counterpart of AddressData: the shape is fixed by the Correios, so every
 * addressable entity — the account itself, its clients — validates it the same
 * way instead of restating the rules.
 */
trait ValidatesAddress
{
    /**
     * @return array<string, mixed>
     */
    protected function addressRules(): array
    {
        return [
            'postal_code' => ['required', 'string', 'regex:/^\d{5}-?\d{3}$/'],
            'street' => ['required', 'string', 'max:255'],
            'number' => ['required', 'string', 'max:20'],
            'complement' => ['nullable', 'string', 'max:100'],
            'district' => ['required', 'string', 'max:120'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['required', Rule::enum(BrazilianState::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function addressAttributes(): array
    {
        return [
            'postal_code' => 'CEP',
            'street' => 'logradouro',
            'number' => 'número',
            'complement' => 'complemento',
            'district' => 'bairro',
            'city' => 'cidade',
            'state' => 'UF',
        ];
    }
}
