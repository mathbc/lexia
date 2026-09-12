<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions\Concerns;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Accounts\Models\Account;
use App\Domain\Shared\Rules\Cnpj;
use App\Domain\Shared\Rules\OabNumber;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Validation shared by account creation and editing.
 *
 * The identifier fields are conditionally required: a firm must supply a CNPJ
 * and a corporate name, an individual an OAB enrolment. `required_if` keeps
 * that rule in one place instead of branching in two Actions.
 */
trait ValidatesAccount
{
    /**
     * @return array<string, mixed>
     */
    protected function accountRules(?Account $ignoring = null): array
    {
        $firm = AccountType::LawFirm->value;
        $individual = AccountType::Individual->value;

        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(AccountType::class)],

            'legal_name' => ["required_if:type,{$firm}", 'nullable', 'string', 'max:255'],

            'federal_id' => [
                "required_if:type,{$firm}",
                'nullable',
                new Cnpj,
                $this->uniqueIdentifier('federal_id', $ignoring),
            ],

            'oab_number' => [
                "required_if:type,{$individual}",
                'nullable',
                new OabNumber,
                $this->uniqueIdentifier('oab_number', $ignoring),
            ],
            'oab_state' => [
                "required_if:type,{$individual}",
                'nullable',
                Rule::enum(BrazilianState::class),
            ],

            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],

            ...$this->addressRules(),
        ];
    }

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
     * Uniqueness has to bypass the tenant scope: a CNPJ already taken by
     * another account is still a conflict, even though that account is
     * invisible to the current user.
     */
    private function uniqueIdentifier(string $column, ?Account $ignoring): Unique
    {
        $rule = Rule::unique('accounts', $column)->whereNull('deleted_at');

        return $ignoring === null ? $rule : $rule->ignore($ignoring);
    }

    /**
     * @return array<string, string>
     */
    protected function accountAttributes(): array
    {
        return [
            'name' => 'nome',
            'legal_name' => 'razão social',
            'type' => 'tipo',
            'federal_id' => 'CNPJ',
            'oab_number' => 'número da OAB',
            'oab_state' => 'seccional da OAB',
            'email' => 'e-mail',
            'phone' => 'telefone',
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
