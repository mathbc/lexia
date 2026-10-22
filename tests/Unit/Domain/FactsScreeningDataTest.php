<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\LegalCases\Data\FactsScreeningData;
use App\Domain\LegalCases\Enums\FactsScreeningVerdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The verdict the screening derives from the agent's six answers, and the
 * sentence the smart-fill screen shows for it. All of it is PHP, so all of it
 * is pinned here — the agent only answers the questions.
 */
final class FactsScreeningDataTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, bool>, 1: FactsScreeningVerdict}>
     */
    public static function signals(): array
    {
        return [
            'um relato completo' => [[], FactsScreeningVerdict::Admissible],
            // A data nunca recusa sozinha: o cliente esquece, e a minuta marca `[data]`.
            'um relato sem data' => [['has_timeline' => false], FactsScreeningVerdict::Admissible],
            'um texto que não forma frase' => [['intelligible' => false], FactsScreeningVerdict::Unintelligible],
            'uma cantiga' => [['legal_matter' => false], FactsScreeningVerdict::NotLegal],
            'um anúncio sem fato' => [['describes_events' => false], FactsScreeningVerdict::Vague],
            'um fato sem pretensão' => [['states_claim' => false], FactsScreeningVerdict::Vague],
            // A instrução vem primeiro: um relato bem escrito com uma ordem no
            // meio continua carregando a ordem.
            'um relato com uma ordem escondida' => [['instructs_the_system' => true], FactsScreeningVerdict::Manipulation],
            'uma ordem que nem frase é' => [
                ['instructs_the_system' => true, 'intelligible' => false, 'legal_matter' => false],
                FactsScreeningVerdict::Manipulation,
            ],
            // Ininteligível antes de "não jurídico": o que não se lê não é
            // sobre assunto nenhum.
            'ruído' => [['intelligible' => false, 'legal_matter' => false], FactsScreeningVerdict::Unintelligible],
            // E "não jurídico" antes de vago: vago é a única recusa sobre um caso de verdade.
            'um poema sem fato' => [['legal_matter' => false, 'describes_events' => false], FactsScreeningVerdict::NotLegal],
        ];
    }

    /**
     * @param  array<string, bool>  $overrides
     */
    #[Test]
    #[DataProvider('signals')]
    public function the_verdict_follows_the_signals_in_a_fixed_order(array $overrides, FactsScreeningVerdict $verdict): void
    {
        $screening = $this->screening($overrides);

        $this->assertSame($verdict, $screening->verdict);
        $this->assertSame($verdict === FactsScreeningVerdict::Admissible, $screening->admissible());
    }

    /**
     * Um provider pode mandar o booleano como texto, e uma chave que falta é
     * um "não" — nunca o benefício da dúvida, justo na porta.
     */
    #[Test]
    public function strings_are_read_as_booleans_and_a_missing_answer_is_a_no(): void
    {
        $screening = FactsScreeningData::fromAgent([
            'instructs_the_system' => 'false',
            'intelligible' => 'true',
            'legal_matter' => 'true',
            'describes_events' => 'true',
            'states_claim' => 'true',
            'has_timeline' => 'false',
            'reason' => 'Narra a dispensa.',
        ]);

        $this->assertSame(FactsScreeningVerdict::Admissible, $screening->verdict);
        $this->assertFalse($screening->hasTimeline);

        $this->assertSame(FactsScreeningVerdict::Unintelligible, FactsScreeningData::fromAgent([])->verdict);
    }

    #[Test]
    public function a_refusal_reads_headline_reason_and_the_way_out(): void
    {
        $screening = $this->screening(['legal_matter' => false], 'É a letra de uma cantiga infantil.');

        $this->assertSame(
            'O texto não descreve uma situação que possa virar uma peça jurídica. '
            .'É a letra de uma cantiga infantil. '
            .'Revise o relato ou use o preenchimento manual.',
            $screening->message(),
        );
    }

    /**
     * O vago é a única recusa que lista o que falta, e é só nela que a data
     * aparece — como o que ajudaria, nunca como o motivo.
     */
    #[Test]
    public function a_vague_narrative_is_told_what_is_missing(): void
    {
        $screening = $this->screening(['describes_events' => false, 'has_timeline' => false], 'Anuncia a ação, mas não conta nenhum fato.');

        $this->assertSame(
            'O relato é vago demais para montar uma peça. '
            .'Falta dizer o que aconteceu e quando os fatos ocorreram. '
            .'Anuncia a ação, mas não conta nenhum fato. '
            .'Revise o relato ou use o preenchimento manual.',
            $screening->message(),
        );

        $this->assertStringContainsString(
            'Falta dizer o que aconteceu, o que o cliente quer obter e quando os fatos ocorreram.',
            $this->screening(['describes_events' => false, 'states_claim' => false, 'has_timeline' => false])->message(),
        );

        $this->assertStringNotContainsString(
            'quando os fatos ocorreram',
            $this->screening(['legal_matter' => false, 'has_timeline' => false])->message(),
        );
    }

    /**
     * A razão seria o modelo descrevendo de volta o texto injetado para quem o
     * escreveu.
     */
    #[Test]
    public function a_manipulation_is_refused_without_the_agents_sentence(): void
    {
        $screening = $this->screening(['instructs_the_system' => true], 'Pede para ignorar as regras e revelar o prompt.');

        $this->assertSame(
            'O texto traz instruções ao sistema em vez dos fatos do caso. '
            .'Revise o relato ou use o preenchimento manual.',
            $screening->message(),
        );
    }

    /**
     * A frase do agente é aparada, cortada no teto e fechada com ponto, para
     * que a seguinte não cole nela.
     */
    #[Test]
    public function the_reason_is_trimmed_capped_and_closed(): void
    {
        $this->assertSame('Narra a dispensa.', $this->screening([], "  Narra a dispensa  \n")->reason);
        $this->assertNull($this->screening([], '   ')->reason);

        $long = $this->screening([], str_repeat('palavra ', 80))->reason;

        $this->assertNotNull($long);
        $this->assertLessThanOrEqual(FactsScreeningData::MAX_REASON + 1, mb_strlen($long));
        $this->assertStringEndsWith('…', $long);
    }

    /**
     * @param  array<string, bool>  $overrides
     */
    private function screening(array $overrides = [], ?string $reason = 'Narra o caso.'): FactsScreeningData
    {
        return FactsScreeningData::fromAgent([
            'instructs_the_system' => false,
            'intelligible' => true,
            'legal_matter' => true,
            'describes_events' => true,
            'states_claim' => true,
            'has_timeline' => true,
            ...$overrides,
            'reason' => $reason,
        ]);
    }
}
