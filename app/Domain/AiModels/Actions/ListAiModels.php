<?php

declare(strict_types=1);

namespace App\Domain\AiModels\Actions;

use App\Domain\AiModels\Models\AiModel;
use App\Domain\AiModels\Support\ModelCatalogPageProps;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The text models the agents can run on, with the default first.
 *
 * Not paginated: the catalogue is a handful of rows kept by hand.
 */
final class ListAiModels
{
    use AsAction;

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('viewAny', AiModel::class);
    }

    public function asController(ActionRequest $request): Response
    {
        return Inertia::render('ai-models/index', ModelCatalogPageProps::for(AiModel::class, $request->user()));
    }
}
