<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\LegalCases\Data\PleadingDraftData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The two readings taken of a drafted document.
 *
 * No database and no inference: pure functions over strings, tested here for
 * the reason RefinedFactsDataTest gives — a model only invents a figure when it
 * feels like it, and a guard that must hold every time cannot be verified by a
 * run that fails intermittently. The agent test next door reads the prose; this
 * one reads the guard.
 *
 * The **gaps** are the reading that has no sibling. A petição inicial qualifies
 * its parties, and the qualification asks for marital status and occupation that
 * `customers` has no column for; the agent is told to write `[estado civil]`
 * rather than guess, so brackets are the healthy outcome and the count on screen
 * is what stops one being filed. Pinned here: what counts as a bracket, and what
 * is prose that merely contains one.
 *
 * The **money** is the guard `RefinedFactsData` already runs, with one
 * difference that matters: what authorises a figure is wider. A request the
 * lawyer wrote with `amount` 50000.00 is as legitimate a source for
 * "R$ 50.000,00" as the client's own account, so the caller joins both and the
 * parser reads the two notations as one.
 */
final class PleadingDraftDataTest extends TestCase
{
    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function documents(): array
    {
        return [
            'a lacuna que a qualificação sempre deixa' => [
                'MARIA DA SILVA, brasileira, [estado civil], [profissão], portadora do CPF...',
                ['[estado civil]', '[profissão]'],
            ],
            'a mesma lacuna duas vezes conta uma' => [
                'Residente em [Endereço Completo]. O réu, em [Endereço Completo].',
                ['[Endereço Completo]'],
            ],
            'uma peça sem lacuna nenhuma' => [
                'EXCELENTÍSSIMO SENHOR DOUTOR JUIZ DE DIREITO DA VARA CÍVEL DE ITAJAÍ/SC',
                [],
            ],
            // O agente escreve "[...]" para elipse, e elipse não é campo a
            // preencher: contá-la diria ao advogado que há trabalho onde não há.
            'a elipse não é lacuna' => [
                'Os fatos [...] seguem narrados, e o autor é [o requerente].',
                ['[o requerente]'],
            ],
            // Um colchete que atravessa a linha é prosa, não campo — o limite
            // está lá para que uma citação longa não vire uma lacuna a preencher.
            'o colchete que atravessa a linha é prosa' => [
                "Conforme [transcrição do acórdão\nque segue anexa] ao presente.",
                [],
            ],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[Test]
    #[DataProvider('documents')]
    public function it_reports_the_gaps_left_for_the_lawyer(string $content, array $expected): void
    {
        $draft = PleadingDraftData::fromAgent(['content' => $content], '');

        $this->assertSame($expected, $draft->placeholders);
        $this->assertSame($expected !== [], $draft->hasGaps());
    }

    /**
     * @return array<string, array{string, string, list<string>}>
     */
    public static function figures(): array
    {
        return [
            'a cifra que o relato escreve' => [
                'O prejuízo foi de R$ 3.200,00.',
                'Requer a condenação ao pagamento de R$ 3.200,00.',
                [],
            ],
            'a cifra que o pedido registrou, em decimal' => [
                'R$ 50000.00',
                'Dá-se à causa o valor de R$ 50.000,00.',
                [],
            ],
            // O erro medido, e o motivo de a guarda existir: a soma chega bem
            // formada e indistinguível de um valor que alguém pediu.
            'o total que ninguém pediu' => [
                'Pagou R$ 4.800,00 por mês durante cinco meses.',
                'Requer R$ 4.800,00 por mês, totalizando R$ 24.000,00.',
                ['24000.00'],
            ],
            'várias cifras inventadas de uma vez' => [
                'O contrato era de R$ 1.000,00.',
                'Requer R$ 1.000,00, mais R$ 500,00 de multa e R$ 200,00 de juros.',
                ['500.00', '200.00'],
            ],
            // Número sem "R$" não é dinheiro: artigo, prazo e placa mudam de
            // forma legitimamente o tempo todo.
            'artigo e prazo não são cifra' => [
                'Houve colisão.',
                'Nos termos do art. 186 do Código Civil, no prazo de 15 dias.',
                [],
            ],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[Test]
    #[DataProvider('figures')]
    public function it_reports_money_no_source_writes(string $sources, string $content, array $expected): void
    {
        $draft = PleadingDraftData::fromAgent(['content' => $content], $sources);

        $this->assertSame($expected, $draft->unsupportedAmounts);
        $this->assertSame($expected !== [], $draft->hasInventedAmounts());
    }

    /**
     * A gramática garante a chave, nunca as páginas.
     */
    #[Test]
    public function a_draft_with_no_document_is_not_written(): void
    {
        $this->assertFalse(PleadingDraftData::fromAgent(['content' => '   '], '')->isWritten());
        $this->assertFalse(PleadingDraftData::fromAgent([], '')->isWritten());
        $this->assertFalse(PleadingDraftData::fromAgent(['content' => null], '')->isWritten());
        $this->assertTrue(PleadingDraftData::fromAgent(['content' => 'Uma peça.'], '')->isWritten());
    }

    #[Test]
    public function it_publishes_the_three_readings_under_the_keys_the_screen_uses(): void
    {
        $draft = PleadingDraftData::fromAgent(
            ['content' => '  Requer R$ 9.000,00 em favor de [o autor].  '],
            '',
        );

        $this->assertSame([
            'content' => 'Requer R$ 9.000,00 em favor de [o autor].',
            'placeholders' => ['[o autor]'],
            'unsupported_amounts' => ['9000.00'],
        ], $draft->toArray());
    }
}
