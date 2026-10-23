<?php

declare(strict_types=1);

namespace App\Domain\AiModels\Actions;

use App\Domain\AiModels\Models\EmbeddingModel;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Removes an embedding model. Vectors stamped with its key stay where they
 * are, already stale, until the default's vectorization replaces them.
 */
final class DeleteEmbeddingModel
{
    use AsAction;

    public function handle(EmbeddingModel $model): void
    {
        $model->delete();
    }

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('delete', $request->route('embeddingModel'));
    }

    public function asController(EmbeddingModel $embeddingModel): RedirectResponse
    {
        $this->handle($embeddingModel);

        Inertia::flash('success', "Modelo {$embeddingModel->name} excluído.");

        return to_route('embedding-models.index');
    }
}
