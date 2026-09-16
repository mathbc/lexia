<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\LegalCases\Actions\Concerns\AdvancesLegalCaseStep;
use App\Domain\LegalCases\Data\FactsData;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Saves the narrative and the urgency — the third step.
 *
 * `facts` is nullable like the rest: the pleading is written in passes, and a
 * lawyer who opens the step to tick the injunction and comes back to the story
 * later is doing the ordinary thing.
 *
 * The injunction is a decision and the description is its justification, so
 * unticking the box clears the text. That happens in FactsData rather than
 * here, because it is a rule about the value and not about this request.
 */
final class UpdateLegalCaseFacts
{
    use AdvancesLegalCaseStep;
    use AsAction;

    public function handle(LegalCase $legalCase, FactsData $data): LegalCase
    {
        $legalCase->update($data->toArray());

        $this->advanceTo($legalCase, LegalCaseStep::Facts->next());

        return $legalCase->refresh();
    }

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('update', $request->route('legalCase'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'facts' => ['nullable', 'string'],
            'injunctive_relief' => ['required', 'boolean'],
            'injunctive_relief_description' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return [
            'facts' => 'fatos',
            'injunctive_relief' => 'tutela de urgência',
            'injunctive_relief_description' => 'descrição da tutela',
        ];
    }

    public function asController(LegalCase $legalCase, ActionRequest $request): RedirectResponse
    {
        $this->handle($legalCase, FactsData::fromArray($request->validated()));

        return to_route('legal-cases.edit', [
            'legalCase' => $legalCase,
            'etapa' => LegalCaseStep::Facts->next()->value,
        ]);
    }
}
