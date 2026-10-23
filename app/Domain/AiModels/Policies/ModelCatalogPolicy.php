<?php

declare(strict_types=1);

namespace App\Domain\AiModels\Policies;

use App\Domain\AiModels\Models\AiModel;
use App\Domain\AiModels\Models\EmbeddingModel;
use App\Domain\Users\Models\User;

/**
 * Who may manage the model catalogues — text and embedding alike.
 *
 * The models are platform infrastructure, not tenant data: which one runs
 * decides what every account's inference costs and where it goes. So the whole
 * catalogue is LexIA staff's, and a customer account never sees it.
 */
final class ModelCatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPlatformAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isPlatformAdmin();
    }

    public function update(User $user, AiModel|EmbeddingModel $model): bool
    {
        return $user->isPlatformAdmin();
    }

    /**
     * Already the default is not something to promote again; for the embedding
     * catalogue that would queue a re-embedding for nothing.
     */
    public function makeDefault(User $user, AiModel|EmbeddingModel $model): bool
    {
        return $user->isPlatformAdmin() && ! $model->default;
    }

    /**
     * The default is never deleted: the platform would have no model to run
     * on. Another one is promoted first.
     */
    public function delete(User $user, AiModel|EmbeddingModel $model): bool
    {
        return $user->isPlatformAdmin() && ! $model->default;
    }
}
