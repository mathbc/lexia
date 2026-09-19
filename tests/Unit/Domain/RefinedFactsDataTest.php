<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\LegalCases\Data\RefinedFactsData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The guard on the money inside a rewritten narrative.
 *
 * No database and no inference: a pure function over two strings, tested here
 * because the agent test cannot test it. That one runs a model, and a model
 * only invents a figure when it feels like it — a guard that must hold every
 * time cannot be verified by a run that fails intermittently.
 *
 * The failure it answers is measured and documented: asked to rewrite "R$
 * 4.800,00 por mês" and "cinco meses em aberto", a small model closes the
 * paragraph with "totalizando R$ 24.000,00". The sum is right and the fact is
 * invented — nobody claimed that number — and by the time it is prose it reads
 * exactly like a figure the client wrote.
 *
 * Unlike the requirement agent's amount, this one is reported rather than
 * dropped: the reasons are in RefinedFactsData, and the short version is that a
 * column can be left blank and a sentence cannot.
 */
final class RefinedFactsDataTest extends TestCase
{
    /**
     * @return array<string, array{string, string, list<string>}>
     */
    public static function narratives(): array
    {
        return [
            'a cifra do relato, repetida' => [
                'Paguei R$ 3.200,00 pela mudança.',
                'A Autora pagou R$ 3.200,00 pelo serviço de mudança.',
                [],
            ],
            'a mesma cifra em outra notação' => [
                'O contrato foi de R$ 25.200,00.',
                'O contrato foi firmado pelo valor de R$ 25200,00.',
                [],
            ],
            'o total que ninguém escreveu' => [
                'São R$ 4.800,00 por mês e estão cinco meses em aberto.',
                'A Ré deixou de pagar cinco parcelas de R$ 4.800,00, totalizando R$ 24.000,00.',
                ['24000.00'],
            ],
            'a multiplicação de um valor unitário' => [
                'Ofereceram R$ 400,00 por caixa extraviada, e foram quatro caixas.',
                'A Ré ofereceu R$ 400,00 por caixa, no total de R$ 1.600,00.',
                ['1600.00'],
            ],
            'a cifra composta do nada' => [
                'Quero ser indenizado pelo transtorno.',
                'A Autora pretende indenização de R$ 5.000,00 pelo abalo sofrido.',
                ['5000.00'],
            ],
            // A guarda olha "R$", e só. Um número solto muda de forma o tempo
            // todo numa reescrita legítima — "sete meses" vira "7 meses" — e
            // recusá-lo seria reprovar o trabalho que o agente foi fazer.
            'número sem cifrão não é cifra' => [
                'Eram 42 caixas e chegaram 38.',
                'Foram transportadas 42 caixas, das quais 38 chegaram ao destino.',
                [],
            ],
            'duas invenções vêm as duas' => [
                'Paguei R$ 900,00 de entrada.',
                'A Autora pagou R$ 900,00 de entrada, restando R$ 2.100,00 do total de R$ 3.000,00.',
                ['2100.00', '3000.00'],
            ],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[Test]
    #[DataProvider('narratives')]
    public function it_reports_every_figure_the_client_did_not_write(
        string $facts,
        string $rewritten,
        array $expected,
    ): void {
        $refined = RefinedFactsData::fromAgent(
            ['facts' => $rewritten, 'impact_basis' => null],
            $facts,
        );

        $this->assertSame($expected, $refined->unsupportedAmounts);
        $this->assertSame($expected !== [], $refined->hasInventedAmounts());
    }

    /**
     * The prose is never touched, whatever the guard found.
     *
     * This is the whole difference from the requirement agent's guard, and it
     * is worth a test of its own: there an invented amount is removed, here it
     * is only pointed at. The sentence around it is usually right, and a
     * narrative with a hole in it is worse than one read carefully.
     */
    #[Test]
    public function it_keeps_the_narrative_even_when_it_names_an_invented_figure(): void
    {
        $rewritten = 'A Ré deixou de pagar cinco parcelas de R$ 4.800,00, totalizando R$ 24.000,00.';

        $refined = RefinedFactsData::fromAgent(
            ['facts' => $rewritten, 'impact_basis' => null],
            'São R$ 4.800,00 por mês e estão cinco meses em aberto.',
        );

        $this->assertSame($rewritten, $refined->facts);
        $this->assertTrue($refined->isWritten());
    }

    /**
     * An empty answer is an empty answer, however the model spelled it.
     *
     * The grammar guarantees both keys exist; it guarantees nothing about what
     * is in them. `isWritten()` is what the Action turns into an error, so that
     * a blank page never reaches the screen as a replacement for the client's
     * own account.
     */
    #[Test]
    public function it_reads_an_empty_answer_as_no_refinement_at_all(): void
    {
        $refined = RefinedFactsData::fromAgent(['facts' => '   ', 'impact_basis' => '  '], 'Relato qualquer.');

        $this->assertFalse($refined->isWritten());
        $this->assertSame('', $refined->facts);
        $this->assertNull($refined->impactBasis);
    }

    /**
     * The payload is the contract: what a screen reads and what the endpoint
     * would publish.
     */
    #[Test]
    public function it_publishes_the_narrative_the_basis_and_the_figures_to_check(): void
    {
        $refined = RefinedFactsData::fromAgent(
            [
                'facts' => 'A Autora perdeu as quatro caixas, no valor de R$ 1.600,00.',
                'impact_basis' => 'O relato narra a perda de bens insubstituíveis.',
            ],
            'Sumiram quatro caixas. Ofereceram R$ 400,00 por caixa.',
        );

        $this->assertSame([
            'facts' => 'A Autora perdeu as quatro caixas, no valor de R$ 1.600,00.',
            'impact_basis' => 'O relato narra a perda de bens insubstituíveis.',
            'unsupported_amounts' => ['1600.00'],
        ], $refined->toArray());
    }
}
