<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\LegalCases\Data\InjunctiveReliefSuggestionData;
use App\Domain\LegalCases\Enums\InjunctiveReliefKind;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The urgent-relief answer read back: normalised, composed into the text the
 * first step offers, and kept as the envelope the screen saves.
 *
 * On the framework's TestCase only for `now()`; nothing here touches the
 * database.
 */
final class InjunctiveReliefSuggestionDataTest extends TestCase
{
    private const string FACTS = 'O banco negativou o nome do Autor por uma dívida já quitada, '
        .'e o financiamento da casa será assinado na semana que vem.';

    #[Test]
    public function a_recommendation_is_composed_into_labelled_paragraphs(): void
    {
        $suggestion = InjunctiveReliefSuggestionData::fromAgent($this->answer(), self::FACTS);

        $this->assertTrue($suggestion->recommended);
        $this->assertSame(InjunctiveReliefKind::Anticipatory, $suggestion->kind);
        $this->assertSame(implode(PHP_EOL.PHP_EOL, [
            'Medida pretendida: que se determine à Ré a exclusão do nome do Autor dos cadastros de proteção ao crédito, no prazo de [prazo].',
            'Espécie: tutela de urgência antecipada.',
            'Fundamento legal: art. 300 do CPC.',
            'Probabilidade do direito: A dívida foi quitada, como mostra o comprovante.',
            'Perigo de dano: O financiamento será assinado na semana que vem.',
            'Reversibilidade: A inscrição pode ser refeita se o débito for devido.',
        ]), $suggestion->description);
        $this->assertSame(['Comprovante de quitação', 'Print da negativação'], $suggestion->evidence);
    }

    /**
     * A irreversibilidade só veda a antecipada, e uma peça que argumenta a
     * reversibilidade de um arresto argumenta o que ninguém perguntou.
     */
    #[Test]
    public function the_precautionary_species_drops_the_reversibility(): void
    {
        $suggestion = InjunctiveReliefSuggestionData::fromAgent(
            [...$this->answer(), 'kind' => 'precautionary', 'measure' => 'que se determine o arresto de bens do Réu'],
            self::FACTS,
        );

        $this->assertSame(InjunctiveReliefKind::Precautionary, $suggestion->kind);
        $this->assertStringContainsString('Espécie: tutela de urgência cautelar.', (string) $suggestion->description);
        $this->assertStringNotContainsString('Reversibilidade', (string) $suggestion->description);
    }

    /**
     * O "não" vence: um modelo que recusa e preenche a medida está dizendo duas
     * coisas, e só a justificativa — qual requisito falta — sobrevive.
     */
    #[Test]
    public function a_refusal_keeps_only_the_justification(): void
    {
        $suggestion = InjunctiveReliefSuggestionData::fromAgent(
            [...$this->answer(), 'recommended' => false, 'justification' => 'O dano já se consumou.'],
            self::FACTS,
        );

        $this->assertFalse($suggestion->recommended);
        $this->assertNull($suggestion->kind);
        $this->assertNull($suggestion->description);
        $this->assertSame([], $suggestion->evidence);
        $this->assertSame('O dano já se consumou.', $suggestion->justification);
    }

    /**
     * A gramática força a chave, não a frase: uma recomendação sem medida é um
     * pedido que o juiz não tem como deferir.
     */
    #[Test]
    public function a_recommendation_without_a_measure_is_not_a_recommendation(): void
    {
        $suggestion = InjunctiveReliefSuggestionData::fromAgent([...$this->answer(), 'measure' => '  '], self::FACTS);

        $this->assertFalse($suggestion->recommended);
        $this->assertNull($suggestion->description);
    }

    /**
     * Prosa não se corta: a multa que ninguém fixou é relatada, e a medida
     * continua de pé para o advogado corrigir.
     */
    #[Test]
    public function a_figure_the_narrative_does_not_write_is_reported(): void
    {
        $suggestion = InjunctiveReliefSuggestionData::fromAgent(
            [...$this->answer(), 'measure' => 'que se determine a exclusão, sob pena de multa diária de R$ 1.000,00'],
            self::FACTS,
        );

        $this->assertSame(['1000.00'], $suggestion->unsupportedAmounts);
        $this->assertStringContainsString('R$ 1.000,00', (string) $suggestion->description);
    }

    #[Test]
    public function the_evidence_is_capped_and_cleaned(): void
    {
        $suggestion = InjunctiveReliefSuggestionData::fromAgent(
            [...$this->answer(), 'evidence' => ['A', ' ', 'B', 'A', 'C', 'D', 'E', 'F']],
            self::FACTS,
        );

        $this->assertSame(['A', 'B', 'C', 'D', 'E'], $suggestion->evidence);
    }

    /**
     * O envelope volta do navegador ao salvar e sai da coluna ao reabrir, e é
     * relido — nunca recalculado.
     */
    #[Test]
    public function the_envelope_survives_the_round_trip(): void
    {
        $suggestion = InjunctiveReliefSuggestionData::fromAgent($this->answer(), self::FACTS);

        $this->assertEquals($suggestion, InjunctiveReliefSuggestionData::fromArray($suggestion->toArray()));
        $this->assertSame('Antecipada', $suggestion->toArray()['kind_label']);
    }

    #[Test]
    public function no_envelope_is_no_suggestion(): void
    {
        $this->assertNull(InjunctiveReliefSuggestionData::fromArray(null));
        $this->assertNull(InjunctiveReliefSuggestionData::fromArray([]));
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(): array
    {
        return [
            'recommended' => true,
            'kind' => 'anticipatory',
            'measure' => 'que se determine à Ré a exclusão do nome do Autor dos cadastros de proteção ao crédito, no prazo de [prazo]',
            'legal_basis' => 'art. 300 do CPC',
            'probability' => 'A dívida foi quitada, como mostra o comprovante.',
            'danger' => 'O financiamento será assinado na semana que vem',
            'reversibility' => 'A inscrição pode ser refeita se o débito for devido.',
            'evidence' => ['Comprovante de quitação', 'Print da negativação'],
            'justification' => 'A negativação indevida ameaça o financiamento marcado.',
        ];
    }
}
