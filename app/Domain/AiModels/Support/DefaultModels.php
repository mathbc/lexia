<?php

declare(strict_types=1);

namespace App\Domain\AiModels\Support;

use App\Domain\AiModels\Exceptions\MissingDefaultModelException;
use App\Domain\AiModels\Models\AiModel;
use App\Domain\AiModels\Models\EmbeddingModel;

/**
 * The models the platform is running on right now.
 *
 * Bound as `scoped`: read once per request or job, and forgotten between them,
 * so a queue worker that outlives a change of default picks up the new one on
 * its next job instead of the one it booted with. A request that prompts five
 * agents asks the database once.
 *
 * Nothing falls back to the environment. A database with no default fails
 * loudly, naming the command that fixes it.
 */
final class DefaultModels
{
    private ?AiModel $text = null;

    private ?EmbeddingModel $embedding = null;

    /**
     * Fixed models instead of the database's — for the tests, which fake the
     * agents and the embeddings and should not need a row to do it.
     */
    public static function using(?AiModel $text = null, ?EmbeddingModel $embedding = null): self
    {
        $models = new self;
        $models->text = $text;
        $models->embedding = $embedding;

        return $models;
    }

    public function text(): AiModel
    {
        return $this->text ??= AiModel::platformDefault() ?? throw MissingDefaultModelException::forText();
    }

    /**
     * The default, provided it can search the web — the research agents'.
     */
    public function webSearchText(): AiModel
    {
        $model = $this->text();

        if (! $model->provider->supportsWebSearch()) {
            throw MissingDefaultModelException::withoutWebSearch($model->displayName());
        }

        return $model;
    }

    public function embedding(): EmbeddingModel
    {
        return $this->embedding ??= EmbeddingModel::platformDefault() ?? throw MissingDefaultModelException::forEmbeddings();
    }
}
