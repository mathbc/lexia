<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\ReportsEmbeddingFailure;
use App\Domain\LegalThemes\Actions\EmbedLegalThemes;
use App\Domain\LegalThemes\Actions\ImportLegalThemes;
use App\Domain\LegalThemes\Data\LegalThemeImportResult;
use App\Domain\LegalThemes\Models\LegalTheme;
use App\Domain\LegalThemes\Support\StjLegalThemesFeed;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Traz os temas do STJ do portal de dados abertos e os deixa prontos para a
 * busca vetorial.
 *
 * Duas etapas com fronteiras diferentes. A importação é uma transação: ou o
 * arquivo inteiro entra, ou nada muda. A vetorização vem depois e fora dela,
 * porque são minutos de inferência e cada lote é gravado ao terminar — se o
 * Ollama cair no meio, os temas já estão na tabela e rodar de novo só embute
 * o que falta, já que o hash pula o que está em dia.
 *
 * Em dia, a segunda execução baixa o arquivo, não cria nada e não embute nada.
 */
final class ImportLegalThemesCommand extends Command
{
    use ReportsEmbeddingFailure;

    /**
     * 2,4 mil vetores de 768 floats hidratados de uma vez passam do
     * `memory_limit` de 128 MB de um PHP de fábrica.
     */
    private const int CHUNK = 256;

    protected $signature = 'lexia:import-legal-themes
                            {--file= : CSV local, em vez do download do portal do STJ}
                            {--skip-embeddings : só importa, sem gerar os vetores}
                            {--fresh : reembute todos os temas, ignorando o hash}';

    protected $description = 'Importa os temas do STJ (dados abertos) e gera os embeddings';

    public function handle(ImportLegalThemes $import, EmbedLegalThemes $embed, StjLegalThemesFeed $feed): int
    {
        $file = $this->option('file');
        $path = is_string($file) && $file !== '' ? $file : $this->download($feed);

        if ($path === null) {
            return self::FAILURE;
        }

        try {
            $result = $this->import($import, $path);
        } finally {
            // Só o que o próprio comando baixou; o --file é do usuário.
            if ($path !== $file) {
                unlink($path);
            }
        }

        if ($result === null) {
            return self::FAILURE;
        }

        return $this->option('skip-embeddings') ? self::SUCCESS : $this->embed($embed);
    }

    private function download(StjLegalThemesFeed $feed): ?string
    {
        $this->info('Baixando '.StjLegalThemesFeed::URL);

        try {
            return $feed->download();
        } catch (Throwable $e) {
            $this->error('Falha ao baixar o arquivo de temas: '.$e->getMessage());

            return null;
        }
    }

    private function import(ImportLegalThemes $import, string $path): ?LegalThemeImportResult
    {
        try {
            $result = $import->handle($path);
        } catch (Throwable $e) {
            $this->error('Falha ao importar os temas; nada foi gravado. '.$e->getMessage());

            return null;
        }

        $this->info(sprintf(
            'Temas do STJ: %d precedentes (%d novos), %d vínculos de repercussão geral.',
            $result->themes,
            $result->created,
            $result->repercussions,
        ));

        return $result;
    }

    private function embed(EmbedLegalThemes $embed): int
    {
        if ($this->option('fresh')) {
            // Pela query base, para não tocar o updated_at: o tema não mudou.
            LegalTheme::query()->toBase()->update(['embedding_hash' => null]);
        }

        $embedded = 0;
        $bar = $this->output->createProgressBar(LegalTheme::query()->count());

        try {
            LegalTheme::query()->chunkById(self::CHUNK, function (Collection $themes) use ($embed, $bar, &$embedded): void {
                $embedded += $embed->handle($themes);
                $bar->advance($themes->count());
            });
        } catch (Throwable $e) {
            $this->newLine();
            $this->reportEmbeddingFailure($e);
            $this->line('Os temas estão gravados, e os lotes que terminaram também. Rode de novo para embutir o resto.');

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine();

        $this->info($embedded === 0
            ? 'Nada a fazer: todos os vetores estão em dia.'
            : "Embeddings gerados: {$embedded}.");

        return self::SUCCESS;
    }
}
