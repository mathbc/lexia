<?php

declare(strict_types=1);

namespace App\Domain\LegalThemes\Actions;

use App\Domain\LegalThemes\Data\LegalThemeImportResult;
use App\Domain\LegalThemes\Data\LegalThemeRecord;
use App\Domain\LegalThemes\Models\GeneralRepercussion;
use App\Domain\LegalThemes\Models\LegalTheme;
use App\Domain\LegalThemes\Support\LegalThemeCsv;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use UnexpectedValueException;

/**
 * Importa o arquivo de temas do STJ para `legal_themes`.
 *
 * Idempotente pela chave do próprio STJ, `sequential_number`: rodar duas vezes
 * sobre o mesmo arquivo não cria nada, e um precedente que mudou (uma tese
 * firmada, uma situação nova) é atualizado no lugar, com o mesmo uuid. O
 * vetor não é tocado aqui — o hash de EmbedLegalThemes é que percebe que o
 * texto mudou.
 *
 * O arquivo inteiro é lido e convertido antes da primeira escrita, e as
 * escritas são uma transação só: ou a tabela passa a refletir o arquivo, ou
 * continua como estava.
 *
 * As repercussões gerais de cada precedente do arquivo são reescritas
 * inteiras, como o pivot do catálogo processual — uma linha filha não tem
 * identidade que valha preservar. Já o precedente que sumiu do arquivo
 * **fica**: o STJ cancela em vez de apagar, e um sumiço é mais provavelmente
 * uma exportação com defeito do que um tema que deixou de existir.
 */
final class ImportLegalThemes
{
    use AsAction;

    /**
     * Cerca de 25 colunas por linha: 500 linhas ficam longe do teto de 65.535
     * parâmetros do Postgres.
     */
    private const int CHUNK = 500;

    public function handle(string $path): LegalThemeImportResult
    {
        $records = (new LegalThemeCsv)->read($path);

        if ($records === []) {
            throw new UnexpectedValueException('O arquivo de temas não tem nenhum precedente.');
        }

        return DB::transaction(function () use ($records): LegalThemeImportResult {
            $before = LegalTheme::query()->count();

            $this->upsertThemes($records);

            return new LegalThemeImportResult(
                themes: count($records),
                created: LegalTheme::query()->count() - $before,
                repercussions: $this->replaceRepercussions($records),
            );
        });
    }

    /**
     * @param  non-empty-list<LegalThemeRecord>  $records
     */
    private function upsertThemes(array $records): void
    {
        $update = array_values(array_diff(array_keys($records[0]->attributes), ['sequential_number']));

        foreach (array_chunk($records, self::CHUNK) as $chunk) {
            $query = LegalTheme::query();

            // fillForInsert aplica os casts (enum, jsonb, datas) e cunha o
            // uuid; no conflito, o uuid e o created_at que valem são os da
            // linha que já existia, porque nenhum dos dois está em $update.
            $query->upsert(
                $query->fillForInsert(array_map(
                    static fn (LegalThemeRecord $record): array => $record->attributes,
                    $chunk,
                )),
                ['sequential_number'],
                $update,
            );
        }
    }

    /**
     * @param  non-empty-list<LegalThemeRecord>  $records
     * @return int quantos vínculos foram gravados
     */
    private function replaceRepercussions(array $records): int
    {
        $ids = LegalTheme::query()
            ->whereIn('sequential_number', array_map(
                static fn (LegalThemeRecord $record): int => $record->sequentialNumber,
                $records,
            ))
            ->pluck('id', 'sequential_number');

        GeneralRepercussion::query()->whereIn('legal_theme_id', $ids->values())->delete();

        $rows = [];

        foreach ($records as $record) {
            foreach ($record->repercussions as $repercussion) {
                $rows[] = ['legal_theme_id' => $ids[$record->sequentialNumber], ...$repercussion];
            }
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            GeneralRepercussion::query()->fillAndInsert($chunk);
        }

        return count($rows);
    }
}
