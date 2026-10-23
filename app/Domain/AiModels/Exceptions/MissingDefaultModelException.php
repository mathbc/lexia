<?php

declare(strict_types=1);

namespace App\Domain\AiModels\Exceptions;

use App\Domain\AiModels\Models\AiModel;
use App\Domain\AiModels\Models\EmbeddingModel;
use RuntimeException;

/**
 * No model is flagged as the platform's default, or the one flagged cannot do
 * what was asked of it.
 *
 * There is no fallback to the environment on purpose: the `.env` no longer
 * names a model nor holds its key, and guessing either would run inference on
 * something nobody chose.
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

    /**
     * The key lives on the row now, and the `.env` no longer has one to fall
     * back to.
     */
    public static function withoutApiKey(AiModel|EmbeddingModel $model): self
    {
        $screen = $model instanceof EmbeddingModel ? '/modelos-de-embedding' : '/modelos-de-ia';

        return new self("O modelo {$model->displayName()} não tem chave de API cadastrada. Informe-a em \"Editar\", em {$screen}.");
    }
}
