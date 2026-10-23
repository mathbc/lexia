<?php

declare(strict_types=1);

namespace App\Domain\AiModels\Actions;

use App\Domain\AiModels\Models\AiModel;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Removes a text model from the catalogue. A hard delete: nothing points at
 * the row, and the default is refused by the policy before it gets here.
 */
final class DeleteAiModel
{
    use AsAction;

    public function handle(AiModel $model): void
    {
        $model->delete();
    }

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('delete', $request->route('aiModel'));
    }

    public function asController(AiModel $aiModel): RedirectResponse
    {
        $this->handle($aiModel);

        Inertia::flash('success', "Modelo {$aiModel->name} excluído.");

        return to_route('ai-models.index');
    }
}
