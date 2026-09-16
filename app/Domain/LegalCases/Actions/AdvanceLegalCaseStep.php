<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\LegalCases\Actions\Concerns\AdvancesLegalCaseStep;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Moves a pleading forward without writing anything else.
 *
 * It exists for the documents step, which persists nothing — the drafts carry
 * their own `File`, and where those files are stored is a decision nobody has
 * taken yet — but whose Continuar still has something true to say: *this
 * pleading reached the documents*. That is a fact about the pleading, and the
 * honest home for a write that is only navigation.
 *
 * It cannot rewind: `advanceTo` keeps the furthest of the two, so this is the
 * one Action whose entire effect is a high-water mark moving or staying put.
 */
final class AdvanceLegalCaseStep
{
    use AdvancesLegalCaseStep;
    use AsAction;

    public function handle(LegalCase $legalCase, LegalCaseStep $step): LegalCase
    {
        $this->advanceTo($legalCase, $step);

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
            'step' => ['required', Rule::enum(LegalCaseStep::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return ['step' => 'etapa'];
    }

    public function asController(LegalCase $legalCase, ActionRequest $request): RedirectResponse
    {
        $step = LegalCaseStep::from($request->string('step')->toString());

        $this->handle($legalCase, $step);

        return to_route('legal-cases.edit', [
            'legalCase' => $legalCase,
            'etapa' => $step->value,
        ]);
    }
}
