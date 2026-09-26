<?php

declare(strict_types=1);

namespace Tests\Feature\LegalThemes;

use App\Domain\LegalThemes\Actions\ImportLegalThemes;
use App\Domain\LegalThemes\Enums\JudgingBody;
use App\Domain\LegalThemes\Enums\LegalThemeType;
use App\Domain\LegalThemes\Models\GeneralRepercussion;
use App\Domain\LegalThemes\Models\LegalTheme;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use UnexpectedValueException;

/**
 * A importação do arquivo de temas do STJ.
 *
 * O fixture são nove linhas reais do arquivo, escolhidas pelo que exercitam:
 * um precedente repetido três vezes, uma por repercussão geral (2137); campos
 * com quebra de linha (1601) e aspas dobradas (1621); audiência pública com
 * data (1629); súmulas (320); e um SIRDR sem órgão julgador (1606). Os
 * terminadores são CRLF, como os do STJ.
 */
final class ImportLegalThemesTest extends TestCase
{
    use RefreshDatabase;

    public const string FIXTURE = __DIR__.'/../../Fixtures/legal-themes.csv';

    #[Test]
    public function it_stores_one_theme_per_precedent_and_the_repercussions_as_children(): void
    {
        $result = ImportLegalThemes::run(self::FIXTURE);

        $this->assertSame(7, $result->themes);
        $this->assertSame(7, $result->created);
        $this->assertSame(5, $result->repercussions);
        $this->assertSame(7, LegalTheme::query()->count());
        $this->assertSame(5, GeneralRepercussion::query()->count());

        $this->assertSame(
            [284, 285, 587],
            $this->theme(2137)->generalRepercussions->pluck('number')->all(),
        );
    }

    #[Test]
    public function it_translates_every_column(): void
    {
        ImportLegalThemes::run(self::FIXTURE);

        $theme = $this->theme(1301);
        $this->assertSame(LegalThemeType::Theme, $theme->type);
        $this->assertSame(952, $theme->number);
        $this->assertSame(JudgingBody::SecondSection, $theme->judging_body);
        $this->assertFalse($theme->public_hearing);
        $this->assertNotNull($theme->settled_thesis);
        $this->assertNotNull($theme->first_assigned_on);
        $this->assertStringContainsString('faixa etária', $theme->question);

        $hearing = $this->theme(1629);
        $this->assertTrue($hearing->public_hearing);
        $this->assertSame('2018-08-27', $hearing->public_hearing_on?->toDateString());

        $sumulas = $this->theme(320);
        $this->assertSame(590, $sumulas->referenced_sumula);
        $this->assertSame(556, $sumulas->originated_sumula);

        $sirdr = $this->theme(1606);
        $this->assertSame(LegalThemeType::Sirdr, $sirdr->type);
        $this->assertNull($sirdr->judging_body);
        $this->assertContains(
            ['code' => 7699, 'name' => 'Juros de Mora - Legais / Contratuais'],
            $sirdr->subjects,
        );

        // A quebra de linha de dentro da célula fica; a do fim, não.
        $multiline = $this->theme(1601);
        $this->assertStringContainsString("\n", (string) $multiline->nugepnac_notes);
        $this->assertStringEndsWith('coletivos.', $multiline->question);
        $this->assertNull($multiline->settled_thesis);

        $this->assertStringContainsString('"para que', (string) $this->theme(1621)->nugepnac_notes);
    }

    #[Test]
    public function a_second_import_updates_in_place_and_rebuilds_the_repercussions(): void
    {
        ImportLegalThemes::run(self::FIXTURE);
        $ids = LegalTheme::query()->orderBy('sequential_number')->pluck('id', 'sequential_number')->all();

        $result = ImportLegalThemes::run($this->fixtureWith(fn (array $rows): array => array_values(array_filter(
            $this->edit($rows, '1301', ['teseFirmada' => 'Tese revisada.']),
            static fn (array $row): bool => $row['numeroRepercussaoGeralSTF'] !== '587',
        ))));

        $this->assertSame(0, $result->created);
        $this->assertSame($ids, LegalTheme::query()->orderBy('sequential_number')->pluck('id', 'sequential_number')->all());
        $this->assertSame('Tese revisada.', $this->theme(1301)->settled_thesis);
        $this->assertSame([284, 285], $this->theme(2137)->generalRepercussions->pluck('number')->all());
    }

    #[Test]
    public function a_precedent_missing_from_the_file_is_kept(): void
    {
        ImportLegalThemes::run(self::FIXTURE);

        ImportLegalThemes::run($this->fixtureWith(static fn (array $rows): array => array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['sequencialPrecedente'] !== '1606',
        ))));

        $this->assertSame(7, LegalTheme::query()->count());
        $this->assertTrue(LegalTheme::query()->where('sequential_number', 1606)->exists());
    }

    #[Test]
    public function the_import_leaves_the_vectors_alone(): void
    {
        // O hash de EmbedLegalThemes é quem decide reembutir; um upsert que
        // zerasse o vetor obrigaria a reembutir o catálogo inteiro a cada
        // importação.
        ImportLegalThemes::run(self::FIXTURE);
        $this->theme(1301)->forceFill(['embedding' => array_fill(0, 768, 0.5), 'embedding_hash' => 'hash'])->save();

        ImportLegalThemes::run(self::FIXTURE);

        $theme = $this->theme(1301);
        $this->assertNotNull($theme->embedding);
        $this->assertSame('hash', $theme->embedding_hash);
    }

    #[Test]
    public function an_unknown_type_aborts_the_import_before_any_write(): void
    {
        ImportLegalThemes::run(self::FIXTURE);

        $file = $this->fixtureWith(fn (array $rows): array => $this->edit(
            $this->edit($rows, '1301', ['teseFirmada' => 'Não deveria entrar.']),
            '1606',
            ['tipoPrecedente' => 'IRDR'],
        ));

        $this->assertThrows(
            static fn () => ImportLegalThemes::run($file),
            UnexpectedValueException::class,
            'Precedente 1606: Tipo de precedente desconhecido: [IRDR].',
        );

        $this->assertNotSame('Não deveria entrar.', $this->theme(1301)->settled_thesis);
    }

    #[Test]
    public function a_renamed_column_aborts_the_import(): void
    {
        $file = $this->fixtureWith(static fn (array $rows): array => $rows, rename: ['teseFirmada' => 'tese']);

        $this->assertThrows(
            static fn () => ImportLegalThemes::run($file),
            UnexpectedValueException::class,
            'não tem as colunas: teseFirmada',
        );

        $this->assertSame(0, LegalTheme::query()->count());
    }

    private function theme(int $sequentialNumber): LegalTheme
    {
        return LegalTheme::query()->where('sequential_number', $sequentialNumber)->sole();
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  array<string, string>  $changes
     * @return list<array<string, string>>
     */
    private function edit(array $rows, string $sequentialNumber, array $changes): array
    {
        return array_map(
            static fn (array $row): array => $row['sequencialPrecedente'] === $sequentialNumber ? [...$row, ...$changes] : $row,
            $rows,
        );
    }

    /**
     * Uma cópia do fixture com as linhas editadas, gravada como o STJ grava.
     *
     * @param  Closure(list<array<string, string>>): list<array<string, string>>  $edit
     * @param  array<string, string>  $rename
     */
    private function fixtureWith(Closure $edit, array $rename = []): string
    {
        $in = fopen(self::FIXTURE, 'rb');
        $this->assertIsResource($in);

        $columns = array_map(strval(...), (array) fgetcsv($in, escape: ''));
        $rows = [];

        while (($values = fgetcsv($in, escape: '')) !== false) {
            $rows[] = array_combine($columns, array_map(strval(...), $values));
        }

        fclose($in);

        $path = (string) tempnam(sys_get_temp_dir(), 'temas-');
        $out = fopen($path, 'wb');
        $this->assertIsResource($out);

        fputcsv($out, array_map(static fn (string $column): string => $rename[$column] ?? $column, $columns), escape: '', eol: "\r\n");

        foreach ($edit($rows) as $row) {
            fputcsv($out, array_values($row), escape: '', eol: "\r\n");
        }

        fclose($out);

        $this->beforeApplicationDestroyed(static fn () => unlink($path));

        return $path;
    }
}
