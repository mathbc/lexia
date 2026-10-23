<?php

declare(strict_types=1);

namespace App\Domain\AiModels\Support;

use App\Domain\AiModels\Enums\AiProvider;
use App\Domain\AiModels\Models\AiModel;
use App\Domain\AiModels\Models\EmbeddingModel;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The props of either catalogue's listing — the two screens are one shape.
 *
 * Each row carries what the actor may do to it, so the row-actions menu
 * arrives already filtered; the policy is still what refuses the request.
 *
 * The API key never leaves: a row says only its last characters, and the form
 * learns from `keyed_providers` which providers refuse to run without one.
 */
final class ModelCatalogPageProps
{
    /**
     * @param  class-string<AiModel|EmbeddingModel>  $class
     * @return array<string, mixed>
     */
    public static function for(string $class, User $actor): array
    {
        /** @var Collection<int, AiModel|EmbeddingModel> $models */
        $models = $class::query()->inCatalogOrder()->get();

        return [
            'models' => $models->map(static fn (AiModel|EmbeddingModel $model): array => [
                'id' => $model->id,
                'provider' => $model->provider->value,
                'provider_label' => $model->provider->label(),
                'name' => $model->name,
                'api_key_hint' => $model->apiKeyHint(),
                'default' => $model->default,
                'can' => [
                    'update' => $actor->can('update', $model),
                    'make_default' => $actor->can('makeDefault', $model),
                    'delete' => $actor->can('delete', $model),
                ],
            ])->all(),
            'providers' => AiProvider::options(),
            'keyed_providers' => array_values(array_map(
                static fn (AiProvider $provider): string => $provider->value,
                array_filter(AiProvider::cases(), static fn (AiProvider $provider): bool => $provider->requiresApiKey()),
            )),
            'can' => [
                'create' => $actor->can('create', $class),
            ],
        ];
    }
}
