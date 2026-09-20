<?php

declare(strict_types=1);

namespace App\Domain\Customers\Actions\Concerns;

use App\Domain\Customers\Enums\CustomerType;
use App\Domain\Customers\Enums\MaritalStatus;
use App\Domain\Customers\Models\Customer;
use App\Domain\Shared\Concerns\ValidatesAddress;
use App\Domain\Shared\Rules\Cnpj;
use App\Domain\Shared\Rules\Cpf;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Validation shared by client creation and editing.
 *
 * The document fields are conditionally required: a person must supply a CPF,
 * a company a CNPJ and a corporate name. `required_if` keeps that in one place
 * instead of branching in two Actions.
 *
 * The qualification fields are the other way round — never required, but never
 * accepted from a company either. `exclude_if` is what says so: sent for a
 * Pessoa Jurídica they leave the validated payload entirely, so a stale marital
 * status cannot ride a type change into the row.
 */
trait ValidatesCustomer
{
    use ValidatesAddress;

    /**
     * @return array<string, mixed>
     */
    protected function customerRules(string $accountId, ?Customer $ignoring = null): array
    {
        $individual = CustomerType::Individual->value;
        $company = CustomerType::Company->value;

        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(CustomerType::class)],

            'legal_name' => ["required_if:type,{$company}", 'nullable', 'string', 'max:255'],

            'cpf' => [
                "required_if:type,{$individual}",
                'nullable',
                new Cpf,
                $this->uniqueDocument('cpf', $accountId, $ignoring),
            ],

            'cnpj' => [
                "required_if:type,{$company}",
                'nullable',
                new Cnpj,
                $this->uniqueDocument('cnpj', $accountId, $ignoring),
            ],

            // Optional even for a person: the pleading writes a bracket for
            // what nobody informed, which beats refusing the registration.
            'marital_status' => ["exclude_if:type,{$company}", 'nullable', Rule::enum(MaritalStatus::class)],
            'occupation' => ["exclude_if:type,{$company}", 'nullable', 'string', 'max:120'],
            'birth_date' => ["exclude_if:type,{$company}", 'nullable', 'date', 'before:today'],

            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],

            ...$this->addressRules(),
        ];
    }

    /**
     * A document is unique within the account, not across the platform: two
     * firms may perfectly well have the same person as a client, and neither
     * should learn that the other does.
     */
    private function uniqueDocument(string $column, string $accountId, ?Customer $ignoring): Unique
    {
        $rule = Rule::unique('customers', $column)
            ->where('account_id', $accountId)
            ->whereNull('deleted_at');

        return $ignoring === null ? $rule : $rule->ignore($ignoring);
    }

    /**
     * @return array<string, string>
     */
    protected function customerAttributes(): array
    {
        return [
            'name' => 'nome',
            'legal_name' => 'razão social',
            'type' => 'tipo',
            'cpf' => 'CPF',
            'cnpj' => 'CNPJ',
            'marital_status' => 'estado civil',
            'occupation' => 'profissão',
            'birth_date' => 'data de nascimento',
            'email' => 'e-mail',
            'phone' => 'telefone',
            ...$this->addressAttributes(),
        ];
    }
}
