<?php

declare(strict_types=1);

namespace App\Domain\AiModels\Enums;

use App\Domain\Shared\Concerns\ProvidesOptions;
use App\Domain\Shared\Contracts\HasLabel;

/**
 * Who serves a registered model.
 *
 * The value names the block under `config('ai.providers')` that ProviderInstance
 * starts from — so a case here without a block there is a model nothing can
 * reach. The block keeps the driver and the URL, read from the environment; the
 * key is the model row's (`api_key`), and ProviderInstance merges it in.
 */
enum AiProvider: string implements HasLabel
{
    use ProvidesOptions;

    case Gemini = 'gemini';
    case Ollama = 'ollama';

    public function label(): string
    {
        return match ($this) {
            self::Gemini => 'Google Gemini',
            self::Ollama => 'Ollama',
        };
    }

    /**
     * Whether the provider runs search and page reading on its side.
     *
     * The research agents depend on it, and the Ollama gateway refuses a
     * provider tool before it builds the request — pointing a researcher there
     * would not degrade the research, it would erase it.
     */
    public function supportsWebSearch(): bool
    {
        return $this === self::Gemini;
    }

    /**
     * Whether a model of this provider is useless without an API key.
     *
     * The local daemon answers anyone who reaches its port; a key there is
     * only for an Ollama behind a proxy that asks for a bearer token.
     */
    public function requiresApiKey(): bool
    {
        return $this === self::Gemini;
    }

    /**
     * Whether embeddings are asked for with the nomic task prefixes.
     *
     * Only the local daemon runs `nomic-embed-text`, the model trained with
     * them. Gemini expresses the same idea as a `taskType`, and would embed a
     * prefix as literal text.
     */
    public function usesTaskPrefixes(): bool
    {
        return $this === self::Ollama;
    }
}
