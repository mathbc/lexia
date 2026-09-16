<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\LegalCases\Actions\Concerns\AdvancesLegalCaseStep;
use App\Domain\LegalCases\Actions\Concerns\ValidatesLegalCaseBasics;
use App\Domain\LegalCases\Data\LegalCaseBasicsData;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Re-saves the first step of a pleading that already exists.
 *
 * The counterpart of CreateLegalCase, and not a redundancy: the lawyer walks
 * back to step 1 on the edit URL to change the client or the class, and without
 * this that Continuar would either be dead or open a second pleading.
 *
 * Note what it does *not* do: the step does not retreat. `advanceTo` takes the
 * furthest of the two, so correcting the heading of a pleading that already
 * reached the requests leaves it there.
 */
final class UpdateLegalCaseBasics
{
    use AdvancesLegalCaseStep;
    use AsAction;
    use ValidatesLegalCaseBasics;

    public function handle(LegalCase $legalCase, LegalCaseBasicsData $data): LegalCase
    {
        $legalCase->update($data->toArray());

        $this->advanceTo($legalCase, LegalCaseStep::Basics->next());

        return $legalCase->refresh();
    }

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('update', $request->route('legalCase'));
    }

    /**
     * The account of the pleading, not of the actor.
     *
     * They are the same account — the Policy just said so — but the record is
     * what the rule is about, and reading it from the request would be one
     * indirection away from the thing being written.
     *
     * @return array<string, mixed>
     */
    public function rules(ActionRequest $request): array
    {
        $legalCase = $request->route('legalCase');

        return $this->basicsRules(
            $legalCase instanceof LegalCase ? $legalCase->account_id : '',
            $request->string('practice_area')->toString(),
        );
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return $this->basicsAttributes();
    }

    public function asController(LegalCase $legalCase, ActionRequest $request): RedirectResponse
    {
        $this->handle($legalCase, $this->basicsData($request->validated()));

        return to_route('legal-cases.edit', [
            'legalCase' => $legalCase,
            'etapa' => LegalCaseStep::Basics->next()->value,
        ]);
    }
}
