<?php

declare(strict_types=1);

namespace App\Domain\AiModels\Enums;

use App\Domain\Shared\Concerns\ProvidesOptions;
use App\Domain\Shared\Contracts\HasLabel;

/**
 * Who serves a registered model.
 *
 * The value is the instance name under `config('ai.providers')`, which is what
 * the SDK resolves a provider by — so a case here without a block there is a
 * model nothing can reach. Credentials and URLs stay in that config, read from
 * the environment: only the choice of model moved to the database.
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
