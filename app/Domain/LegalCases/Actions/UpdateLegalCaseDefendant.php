<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\LegalCases\Actions\Concerns\AdvancesLegalCaseStep;
use App\Domain\LegalCases\Data\DefendantData;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Saves the other party — the second step of the form.
 *
 * Nothing is required, and that is the rule rather than an omission. A
 * defendant is described, not registered: what the lawyer has at drafting time
 * is often a name and little else, and refusing the step over a missing postal
 * code would refuse the truth. `defendant_notes` is where the rest goes.
 *
 * The rules are written out here instead of leaning on ValidatesAddress: that
 * trait's keys carry no prefix and six of its seven rules are `required`, which
 * is the opposite of this on both axes. See DefendantData.
 */
final class UpdateLegalCaseDefendant
{
    use AdvancesLegalCaseStep;
    use AsAction;

    public function handle(LegalCase $legalCase, DefendantData $data): LegalCase
    {
        $legalCase->update($data->toArray());

        $this->advanceTo($legalCase, LegalCaseStep::Defendant->next());

        return $legalCase->refresh();
    }

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('update', $request->route('legalCase'));
    }

    /**
     * Every rule is `nullable`, and every one of them matters: ConvertEmpty
     * StringsToNull is in the global stack, so an untouched field arrives as
     * null rather than '' — a `Rule::enum` would reject the empty string.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'defendant_name' => ['nullable', 'string', 'max:255'],

            // Um campo para CPF e CNPJ: o réu é descrito, não cadastrado, e o
            // comprimento é o que diz qual dos dois é.
            'defendant_document' => ['nullable', 'string', 'max:18'],

            'defendant_email' => ['nullable', 'email:rfc', 'max:255'],
            'defendant_phone' => ['nullable', 'string', 'max:20'],

            'defendant_postal_code' => ['nullable', 'string', 'regex:/^\d{5}-?\d{3}$/'],
            'defendant_street' => ['nullable', 'string', 'max:255'],
            'defendant_number' => ['nullable', 'string', 'max:20'],
            'defendant_complement' => ['nullable', 'string', 'max:100'],
            'defendant_district' => ['nullable', 'string', 'max:120'],
            'defendant_city' => ['nullable', 'string', 'max:120'],
            'defendant_state' => ['nullable', Rule::enum(BrazilianState::class)],

            'defendant_notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return [
            'defendant_name' => 'nome',
            'defendant_document' => 'CPF/CNPJ',
            'defendant_email' => 'e-mail',
            'defendant_phone' => 'telefone',
            'defendant_postal_code' => 'CEP',
            'defendant_street' => 'logradouro',
            'defendant_number' => 'número',
            'defendant_complement' => 'complemento',
            'defendant_district' => 'bairro',
            'defendant_city' => 'cidade',
            'defendant_state' => 'UF',
            'defendant_notes' => 'observações',
        ];
    }

    public function asController(LegalCase $legalCase, ActionRequest $request): RedirectResponse
    {
        $this->handle($legalCase, DefendantData::fromArray($request->validated()));

        return to_route('legal-cases.edit', [
            'legalCase' => $legalCase,
            'etapa' => LegalCaseStep::Defendant->next()->value,
        ]);
    }
}
