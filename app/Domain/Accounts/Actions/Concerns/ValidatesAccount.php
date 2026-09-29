<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions\Concerns;

use App\Domain\Accounts\Enums\AccountLogo;
use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Accounts\Models\Account;
use App\Domain\Shared\Concerns\ValidatesAddress;
use App\Domain\Shared\Rules\Cnpj;
use App\Domain\Shared\Rules\OabNumber;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
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
    use ValidatesAddress;

    /**
     * @return array<string, mixed>
     */
    protected function accountRules(?Account $ignoring = null): array
    {
        $firm = AccountType::LawFirm->value;
        $individual = AccountType::Individual->value;

        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in($this->assignableTypes($ignoring))],

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
     * The logos, apart from the account rules because only the panel offers
     * them: public sign-up asks for the essentials and nothing more.
     *
     * PNG and JPEG and nothing else. SVG is the format designers hand over,
     * and the one that runs script when opened from our own origin; WebP is
     * what PhpWord cannot embed in the DOCX export. The dimension cap is not
     * about looks: a small PNG can decompress to a bitmap that exhausts memory
     * wherever it is read back — and the pleading's is read back by dompdf
     * and PhpWord on every export.
     *
     * No aspect ratio is imposed on any of them: the square the sidebar wants
     * and the wide mark the letterhead has room for are advice on the screen,
     * and both boxes contain whatever arrives without distorting it.
     *
     * @return array<string, mixed>
     */
    protected function logoRules(): array
    {
        $rules = [];

        foreach (AccountLogo::cases() as $logo) {
            $rules[$logo->field()] = [
                'nullable',
                File::image()
                    ->types(['png', 'jpg', 'jpeg'])
                    ->max(2 * 1024)
                    ->dimensions(Rule::dimensions()->maxWidth(4000)->maxHeight(4000)),
            ];
            $rules[$logo->removalField()] = ['boolean'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function logoAttributes(): array
    {
        $attributes = [];

        foreach (AccountLogo::cases() as $logo) {
            $attributes[$logo->field()] = $logo->attribute();
            $attributes[$logo->removalField()] = "remoção da {$logo->attribute()}";
        }

        return $attributes;
    }

    /**
     * Which types a form may set.
     *
     * The platform type is never on offer: it belongs to the single account
     * created by migration. The account that already has it keeps it, so
     * editing LexIA's own details does not silently demote it to a customer.
     *
     * @return list<string>
     */
    private function assignableTypes(?Account $account): array
    {
        $types = AccountType::customerValues();

        return $account?->type === AccountType::Platform
            ? [...$types, AccountType::Platform->value]
            : $types;
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
            ...$this->addressAttributes(),
        ];
    }
}
