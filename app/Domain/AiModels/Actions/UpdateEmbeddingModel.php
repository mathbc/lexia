<?php

declare(strict_types=1);

namespace App\Domain\AiModels\Actions;

use App\Domain\AiModels\Actions\Concerns\ValidatesModelCatalog;
use App\Domain\AiModels\Enums\AiProvider;
use App\Domain\AiModels\Models\EmbeddingModel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Corrects an embedding model.
 *
 * Renaming the default is a change of model in all but name: the key the
 * vectors are stamped with stops matching, so the catalogues are queued for
 * re-embedding exactly as a promotion would.
 */
final class UpdateEmbeddingModel
{
    use AsAction;
    use ValidatesModelCatalog;

    public function handle(EmbeddingModel $model, AiProvider $provider, string $name): EmbeddingModel
    {
        return DB::transaction(function () use ($model, $provider, $name): EmbeddingModel {
            $model->update(['provider' => $provider, 'name' => $name]);

            if ($model->default && $model->wasChanged(['provider', 'name'])) {
                EmbedCatalogs::dispatch()->afterCommit();
            }

            return $model;
        });
    }

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('update', $request->route('embeddingModel'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(ActionRequest $request): array
    {
        /** @var EmbeddingModel $model */
        $model = $request->route('embeddingModel');

        return $this->catalogRules('embedding_models', $request, $model);
    }

    public function asController(EmbeddingModel $embeddingModel, ActionRequest $request): RedirectResponse
    {
        $this->handle(
            $embeddingModel,
            AiProvider::from($request->string('provider')->toString()),
            $request->string('name')->toString(),
        );

        Inertia::flash('success', "Modelo {$embeddingModel->name} atualizado.");

        return to_route('embedding-models.index');
    }
}
