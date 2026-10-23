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
 * Registers an embedding model. As with the text ones, the first row of an
 * empty catalogue becomes the default — and, being a change of default, it
 * queues the vectorization through MakeEmbeddingModelDefault.
 */
final class CreateEmbeddingModel
{
    use AsAction;
    use ValidatesModelCatalog;

    public function handle(AiProvider $provider, string $name): EmbeddingModel
    {
        return DB::transaction(function () use ($provider, $name): EmbeddingModel {
            $model = EmbeddingModel::query()->create(['provider' => $provider, 'name' => $name]);

            if (! EmbeddingModel::hasDefault()) {
                MakeEmbeddingModelDefault::make()->handle($model);
            }

            return $model;
        });
    }

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('create', EmbeddingModel::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(ActionRequest $request): array
    {
        return $this->catalogRules('embedding_models', $request);
    }

    public function asController(ActionRequest $request): RedirectResponse
    {
        $model = $this->handle(
            AiProvider::from($request->string('provider')->toString()),
            $request->string('name')->toString(),
        );

        Inertia::flash('success', $model->default
            ? "Modelo {$model->name} cadastrado e definido como padrão. Os catálogos estão sendo vetorizados em segundo plano."
            : "Modelo {$model->name} cadastrado.");

        return to_route('embedding-models.index');
    }
}
