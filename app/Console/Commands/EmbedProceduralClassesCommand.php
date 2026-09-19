<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\ProceduralClasses\Actions\EmbedProceduralClasses;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use Illuminate\Console\Command;
use Throwable;

/**
 * Prepara o catálogo para a busca vetorial.
 *
 * Roda depois de qualquer migration que mexa nas descrições, nas matérias
 * típicas ou nos artigos de base — a migration de recarga zera os hashes
 * justamente para que esta passada reembuta tudo. Em dia, não faz nada e
 * termina em milissegundos.
 *
 * Não é obrigatório: SelectProceduralClass embute as candidatas que faltarem
 * antes de ranquear. O comando existe para pagar esse custo de uma vez, fora
 * do caminho da primeira classificação.
 */
final class EmbedProceduralClassesCommand extends Command
{
    protected $signature = 'lexia:embed-procedural-classes
                            {--fresh : reembute todas, ignorando o hash}
                            {--filing-only : só as classes de ajuizamento, que são as que o agente vê}';

    protected $description = 'Gera os embeddings das classes processuais para a busca vetorial';

    public function handle(EmbedProceduralClasses $embed): int
    {
        $query = ProceduralClass::query();

        if ($this->option('filing-only')) {
            $query->where('is_filing_class', true);
        }

        $classes = $query->get();

        if ($this->option('fresh')) {
            // Zerar o hash é o que força a reembutida: a Action pula tudo que
            // já bate com o texto atual.
            ProceduralClass::query()->whereKey($classes->modelKeys())->update([
                'embedding_hash' => null,
            ]);

            $classes->each->setAttribute('embedding_hash', null);
        }

        $this->info("Catálogo: {$classes->count()} classes.");

        try {
            $embedded = $embed->handle($classes);
        } catch (Throwable $e) {
            $provider = (string) config('ai.default_for_embeddings');
            $model = (string) config("ai.providers.{$provider}.models.embeddings.default");

            $this->error('Falha ao gerar embeddings: '.$e->getMessage());
            $this->line("Provedor de embeddings: [{$provider}], modelo [{$model}].");
            $this->line($provider === 'ollama'
                ? "Verifique se o Ollama está de pé e se o modelo foi baixado: ollama pull {$model}"
                : 'Verifique a credencial do provedor e se o modelo aceita as dimensões configuradas.');

            return self::FAILURE;
        }

        $this->info($embedded === 0
            ? 'Nada a fazer: todos os vetores estão em dia.'
            : "Embeddings gerados: {$embedded}.");

        return self::SUCCESS;
    }
}
