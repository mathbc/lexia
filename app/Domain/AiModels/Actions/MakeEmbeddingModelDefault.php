<?php

declare(strict_types=1);

namespace App\Domain\AiModels\Actions;

use App\Domain\AiModels\Models\EmbeddingModel;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Switches the catalogues to another embedding model.
 *
 * Not a flag flip like the text one: the vectors on disk were made by the old
 * model and do not compare with the new one's. So the switch queues
 * EmbedCatalogs, after the commit — a worker that picked it up first would read
 * the old default and find nothing to do.
 */
final class MakeEmbeddingModelDefault
{
    use AsAction;

    public function handle(EmbeddingModel $model): void
    {
        $model->makeDefault();

        EmbedCatalogs::dispatch()->afterCommit();
    }

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('makeDefault', $request->route('embeddingModel'));
    }

    public function asController(EmbeddingModel $embeddingModel): RedirectResponse
    {
        $this->handle($embeddingModel);

        Inertia::flash('success', "Os catálogos passam a usar {$embeddingModel->displayName()} e estão sendo revetorizados em segundo plano.");

        return to_route('embedding-models.index');
    }
}
