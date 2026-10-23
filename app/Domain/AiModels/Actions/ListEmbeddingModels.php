<?php

declare(strict_types=1);

namespace App\Domain\AiModels\Actions;

use App\Domain\AiModels\Models\EmbeddingModel;
use App\Domain\AiModels\Support\ModelCatalogPageProps;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The embedding models the catalogues can be vectorized with, default first.
 */
final class ListEmbeddingModels
{
    use AsAction;

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('viewAny', EmbeddingModel::class);
    }

    public function asController(ActionRequest $request): Response
    {
        return Inertia::render('embedding-models/index', ModelCatalogPageProps::for(EmbeddingModel::class, $request->user()));
    }
}
