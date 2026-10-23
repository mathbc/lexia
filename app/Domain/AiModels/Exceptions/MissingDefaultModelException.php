<?php

declare(strict_types=1);

namespace App\Domain\AiModels\Exceptions;

use RuntimeException;

/**
 * No model is flagged as the platform's default, or the one flagged cannot do
 * what was asked of it.
 *
 * There is no fallback to the environment on purpose: the `.env` no longer
 * names a model, and guessing one would run inference on something nobody
 * chose.
 */
final class MissingDefaultModelException extends RuntimeException
{
    public static function forText(): self
    {
        return new self('Nenhum modelo de IA padrão cadastrado. Rode `php artisan lexia:install` ou defina um em /modelos-de-ia.');
    }

    public static function forEmbeddings(): self
    {
        return new self('Nenhum modelo de embedding padrão cadastrado. Rode `php artisan lexia:install` ou defina um em /modelos-de-embedding.');
    }

    public static function withoutWebSearch(string $model): self
    {
        return new self("A pesquisa precisa de um modelo com busca na web, e o modelo de IA padrão, {$model}, não tem. Defina um modelo do Google Gemini como padrão em /modelos-de-ia.");
    }
}
