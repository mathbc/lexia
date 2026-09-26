<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\LegalThemes\Data\LegalThemeRecord;
use App\Domain\LegalThemes\Enums\JudgingBody;
use App\Domain\LegalThemes\Enums\LegalThemeType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * As conversões célula a célula do arquivo de temas do STJ.
 *
 * A regra é uma só e vale para todas: célula vazia é null, e célula que não é
 * nem vazia nem o que a coluna guarda derruba a importação. Um palpite aqui
 * seria um tema gravado errado sem ninguém saber.
 */
final class LegalThemeRecordTest extends TestCase
{
    /**
     * @return array<string, array{string, list<array{code: int, name: string}>}>
     */
    public static function subjects(): array
    {
        return [
            'vazio' => ['', []],
            'um assunto' => ['12486- Planos de saúde', [['code' => 12486, 'name' => 'Planos de saúde']]],
            'o espaço antes da vírgula' => [
                '8866- Litisconsórcio , 899- DIREITO CIVIL',
                [['code' => 8866, 'name' => 'Litisconsórcio'], ['code' => 899, 'name' => 'DIREITO CIVIL']],
            ],
            'barra e parênteses no nome' => [
                '6017- Dívida Ativa (Execução Fiscal), 7699- Juros de Mora - Legais / Contratuais',
                [['code' => 6017, 'name' => 'Dívida Ativa (Execução Fiscal)'], ['code' => 7699, 'name' => 'Juros de Mora - Legais / Contratuais']],
            ],
            'vírgula dentro do nome' => [
                '100- Obrigações, em geral, 200- Contratos',
                [['code' => 100, 'name' => 'Obrigações, em geral'], ['code' => 200, 'name' => 'Contratos']],
            ],
        ];
    }

    /**
     * @param  list<array{code: int, name: string}>  $expected
     */
    #[Test]
    #[DataProvider('subjects')]
    public function it_splits_the_subjects_at_each_code(string $value, array $expected): void
    {
        $this->assertSame($expected, LegalThemeRecord::subjects($value));
    }

    #[Test]
    public function blank_cells_are_null(): void
    {
        $this->assertNull(LegalThemeRecord::flag(''));
        $this->assertNull(LegalThemeRecord::date(' '));
        $this->assertNull(LegalThemeRecord::integer(''));
        $this->assertNull(JudgingBody::fromSource(''));
    }

    #[Test]
    public function well_formed_cells_are_converted(): void
    {
        $this->assertTrue(LegalThemeRecord::flag('S'));
        $this->assertFalse(LegalThemeRecord::flag('N'));
        $this->assertSame('2018-06-27', LegalThemeRecord::date('2018-06-27'));
        $this->assertSame(590, LegalThemeRecord::integer('590'));
        $this->assertSame(LegalThemeType::Controversy, LegalThemeType::fromSource('Controvérsia'));
        $this->assertSame(LegalThemeType::Theme, LegalThemeType::fromSource('Tema'));
        $this->assertSame(JudgingBody::SpecialCourt, JudgingBody::fromSource('CE'));
    }

    /**
     * @return array<string, array{callable(): mixed}>
     */
    public static function malformedCells(): array
    {
        return [
            'S/N por extenso' => [static fn (): ?bool => LegalThemeRecord::flag('Sim')],
            'data no formato brasileiro' => [static fn (): ?string => LegalThemeRecord::date('27/06/2018')],
            'data que não existe' => [static fn (): ?string => LegalThemeRecord::date('2023-02-30')],
            'número com pontuação' => [static fn (): ?int => LegalThemeRecord::integer('1.016')],
            'tipo que o código não conhece' => [static fn (): LegalThemeType => LegalThemeType::fromSource('IRDR')],
            'órgão que o código não conhece' => [static fn (): ?JudgingBody => JudgingBody::fromSource('T1')],
        ];
    }

    #[Test]
    #[DataProvider('malformedCells')]
    public function a_malformed_cell_throws_instead_of_guessing(callable $convert): void
    {
        $this->expectException(UnexpectedValueException::class);

        $convert();
    }

    #[Test]
    public function a_precedent_spread_over_rows_keeps_one_of_each_repercussion(): void
    {
        $row = array_fill_keys(LegalThemeRecord::COLUMNS, '');
        $row = [
            ...$row,
            'sequencialPrecedente' => '2137',
            'tipoPrecedente' => 'Controvérsia',
            'numeroPrecedente' => '75',
            'situacao' => 'Cancelada',
            'questaoSubmetidaAJulgamento' => "A questão.\n",
        ];

        $record = LegalThemeRecord::fromRows([
            [...$row, 'numeroRepercussaoGeralSTF' => '284', 'descricaoRepercussaoGeral' => 'Poupança bloqueada.'],
            [...$row, 'numeroRepercussaoGeralSTF' => '285', 'descricaoRepercussaoGeral' => 'Poupança não bloqueada.'],
            [...$row, 'numeroRepercussaoGeralSTF' => '284', 'descricaoRepercussaoGeral' => 'Poupança bloqueada.'],
        ]);

        $this->assertSame(2137, $record->sequentialNumber);
        $this->assertSame('A questão.', $record->attributes['question']);
        $this->assertSame([
            ['number' => 284, 'description' => 'Poupança bloqueada.'],
            ['number' => 285, 'description' => 'Poupança não bloqueada.'],
        ], $record->repercussions);
    }
}
