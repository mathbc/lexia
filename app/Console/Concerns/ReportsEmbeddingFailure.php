<?php

declare(strict_types=1);

namespace App\Console\Concerns;

use App\Domain\AiModels\Enums\AiProvider;
use App\Domain\AiModels\Support\DefaultModels;
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
        $this->error('Falha ao gerar embeddings: '.$e->getMessage());

        try {
            $model = app(DefaultModels::class)->embedding();
        } catch (Throwable) {
            // Sem padrão, a mensagem acima já diz o que rodar.
            return;
        }

        $this->line("Provedor de embeddings: [{$model->provider->value}], modelo [{$model->name}].");
        $this->line($model->provider === AiProvider::Ollama
            ? "Verifique se o Ollama está de pé e se o modelo foi baixado: ollama pull {$model->name}"
            : 'Verifique a credencial do provedor e se o modelo aceita 768 dimensões.');
    }
}
