<?php

declare(strict_types=1);

namespace App\Domain\AiModels\Support;

use App\Domain\AiModels\Exceptions\MissingDefaultModelException;
use App\Domain\AiModels\Models\AiModel;
use App\Domain\AiModels\Models\EmbeddingModel;

/**
 * The SDK provider instance a model's requests go through, carrying the
 * model's own API key.
 *
 * The SDK resolves a provider by name from `config('ai.providers')` and keeps
 * the instance for the life of the process, so the key cannot just be written
 * over the provider's block: the text and the embedding default may both be
 * Gemini with different keys and run in the same request, and a queue worker
 * would hold on to the first key it saw after staff replaced it. So each key
 * gets an instance of its own — the provider's block plus the key, under a name
 * derived from it (`gemini-3f1a…`). Two rows with the same key share one, and a
 * new key is a new name, never a stale instance.
 *
 * A model without a key runs on the bare block (`ollama`), which is how the
 * local daemon is reached; one whose provider requires a key refuses here,
 * naming the screen that fixes it, instead of reaching the provider with none.
 *
 * The name is what the agents answer from `provider()` and what DocumentEmbedder
 * passes to `generate()`. The driver underneath is still the provider's, and it
 * is the driver — not this name — that `providerOptions()` receives.
 */
final class ProviderInstance
{
    public static function for(AiModel|EmbeddingModel $model): string
    {
        $provider = $model->provider->value;
        $key = (string) $model->api_key;

        if ($key === '' && $model->provider->requiresApiKey()) {
            throw MissingDefaultModelException::withoutApiKey($model);
        }

        // A digest and not the key: the name reaches events and logs.
        $name = $key === '' ? $provider : $provider.'-'.substr(hash('sha256', $key), 0, 16);

        config(["ai.providers.{$name}" => ['key' => $key] + (array) config("ai.providers.{$provider}")]);

        return $name;
    }
}
