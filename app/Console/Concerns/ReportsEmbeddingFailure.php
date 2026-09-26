<?php

declare(strict_types=1);

namespace App\Console\Concerns;

use Illuminate\Console\Command;
use Throwable;

/**
 * O diagnóstico de uma vetorização que falhou: qual provedor e qual modelo
 * foram tentados, e o que conferir em cada caso.
 *
 * @mixin Command
 */
trait ReportsEmbeddingFailure
{
    protected function reportEmbeddingFailure(Throwable $e): void
    {
        $provider = (string) config('ai.default_for_embeddings');
        $model = (string) config("ai.providers.{$provider}.models.embeddings.default");

        $this->error('Falha ao gerar embeddings: '.$e->getMessage());
        $this->line("Provedor de embeddings: [{$provider}], modelo [{$model}].");
        $this->line($provider === 'ollama'
            ? "Verifique se o Ollama está de pé e se o modelo foi baixado: ollama pull {$model}"
            : 'Verifique a credencial do provedor e se o modelo aceita as dimensões configuradas.');
    }
}
