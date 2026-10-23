<?php

declare(strict_types=1);

namespace App\Ai\Concerns;

use App\Domain\AiModels\Models\AiModel;
use App\Domain\AiModels\Support\DefaultModels;
use App\Domain\AiModels\Support\ProviderInstance;

/**
 * Runs the agent on the platform's default text model.
 *
 * The SDK asks an agent's `provider()` and `model()` before it looks at a
 * `#[Provider]` or `#[Model]` attribute, so these two methods are the whole
 * mechanism: no agent names a provider any more, and switching every one of
 * them is promoting another row in /modelos-de-ia. Both are read per prompt
 * from DefaultModels, which caches the row for the request or job, so the
 * process children of a `Concurrency::run` read it too.
 *
 * `provider()` answers the instance ProviderInstance registers for the row,
 * not the bare provider name: the instance is what carries the row's API key.
 *
 * Whatever runs, ConfiguresOllamaRuntime still applies: it sends `num_ctx`,
 * `keep_alive` and `think` when the default is an Ollama model and nothing
 * otherwise.
 */
trait UsesDefaultAiModel
{
    public function provider(): string
    {
        return ProviderInstance::for($this->defaultModel());
    }

    public function model(): string
    {
        return $this->defaultModel()->name;
    }

    protected function defaultModel(): AiModel
    {
        return app(DefaultModels::class)->text();
    }
}
