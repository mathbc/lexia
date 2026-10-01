<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\LegalCases\Actions\Concerns\ValidatesLegalCaseBasics;
use App\Domain\LegalCases\Data\LegalCaseBasicsData;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Opens a pleading, at the close of the form's first step.
 *
 * The row is born here and is finished nowhere: the assembly form saves one
 * step at a time, so what exists after this is a draft standing on the second
 * step, with a client, a heading and nothing else. That is the point of saving
 * this early — from here on the work survives closing the tab.
 *
 * `is_draft` is left to the column's default rather than set: every pleading is
 * a draft today, and writing it out here would suggest this Action had a say.
 *
 * O relato e a tutela são desta etapa, e o relato é opcional. Numa peça
 * montada à mão a caixa pode ir vazia, e o servidor grava null.
 *
 * O preenchimento inteligente não passa mais por aqui: ele grava o próprio
 * rascunho por `CreateAssistedLegalCase`, ao fim do enquadramento. Só a
 * reserva dele — a entrega pelo `sessionStorage`, quando a peça não pôde ser
 * gravada — chega a este "Continuar" com o relato já escrito.
 */
final class CreateLegalCase
{
    use AsAction;
    use ValidatesLegalCaseBasics;

    public function handle(string $accountId, string $userId, LegalCaseBasicsData $data): LegalCase
    {
        $legalCase = new LegalCase($data->toArray());

        // Explicit rather than left to BelongsToAccount: the account is an
        // argument of this use case, so it is set where it can be seen. The
        // user is the same kind of fact — who opened it, which the dashboard
        // counts by — and is set the same way.
        $legalCase->account_id = $accountId;
        $legalCase->user_id = $userId;

        // The first step is done, so the pleading now stands on the second.
        $legalCase->current_step = LegalCaseStep::Basics->next();

        $legalCase->save();

        return $legalCase;
    }

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('create', LegalCase::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(ActionRequest $request): array
    {
        return $this->basicsRules(
            $request->user()->account_id,
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

    public function asController(ActionRequest $request): RedirectResponse
    {
        $legalCase = $this->handle(
            $request->user()->account_id,
            $request->user()->id,
            $this->basicsData($request->validated()),
        );

        return to_route('legal-cases.edit', [
            'legalCase' => $legalCase,
            'etapa' => $legalCase->current_step->value,
        ]);
    }
}
