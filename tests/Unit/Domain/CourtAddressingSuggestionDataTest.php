<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\JudicialSystems\Data\JudicialSystemSelection;
use App\Domain\JudicialSystems\Enums\AdoptionStatus;
use App\Domain\JudicialSystems\Models\JudicialSystem;
use App\Domain\LegalCases\Data\CourtAddressingSuggestionData;
use App\Domain\LegalCases\Data\ForumPlace;
use App\Domain\LegalCases\Enums\CourtDivision;
use App\Domain\LegalCases\Enums\ForumSource;
use App\Domain\ProceduralClasses\Enums\JusticeBranch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The addressing composed out of its parts, the place guard, and the envelope
 * the first step saves.
 *
 * On the framework's TestCase only for `now()`; nothing here touches the
 * database.
 */
final class CourtAddressingSuggestionDataTest extends TestCase
{
    /**
     * A forma é sempre neutra, e cada justiça nomeia o juiz e o território do
     * seu jeito.
     *
     * @return iterable<string, array{CourtDivision, ForumPlace, string}>
     */
    public static function addressings(): iterable
    {
        $joinville = new ForumPlace('Joinville', BrazilianState::SC);

        yield 'vara estadual' => [CourtDivision::Civil, $joinville, 'Excelentíssimo(a) Senhor(a) Juiz(a) de Direito da Vara Cível da Comarca de Joinville/SC'];
        yield 'juizado estadual' => [CourtDivision::SmallClaims, $joinville, 'Excelentíssimo(a) Senhor(a) Juiz(a) de Direito do Juizado Especial Cível da Comarca de Joinville/SC'];
        yield 'família' => [CourtDivision::Family, $joinville, 'Excelentíssimo(a) Senhor(a) Juiz(a) de Direito da Vara de Família e Sucessões da Comarca de Joinville/SC'];
        yield 'federal' => [CourtDivision::Federal, $joinville, 'Excelentíssimo(a) Senhor(a) Juiz(a) Federal da Vara Federal da Subseção Judiciária de Joinville/SC'];
        yield 'trabalho' => [CourtDivision::Labor, $joinville, 'Excelentíssimo(a) Senhor(a) Juiz(a) do Trabalho da Vara do Trabalho de Joinville/SC'];
        yield 'distrito federal' => [CourtDivision::Civil, new ForumPlace('Brasília', BrazilianState::DF), 'Excelentíssimo(a) Senhor(a) Juiz(a) de Direito da Vara Cível da Circunscrição Judiciária de Brasília/DF'];
        yield 'sem cidade' => [CourtDivision::Civil, new ForumPlace(null, BrazilianState::RS), 'Excelentíssimo(a) Senhor(a) Juiz(a) de Direito da Vara Cível da Comarca de [CIDADE]/RS'];
        yield 'sem lugar' => [CourtDivision::Civil, new ForumPlace(null, null), 'Excelentíssimo(a) Senhor(a) Juiz(a) de Direito da Vara Cível da Comarca de [CIDADE/UF]'];
    }

    #[Test]
    #[DataProvider('addressings')]
    public function the_addressing_is_composed_in_the_neutral_form(CourtDivision $division, ForumPlace $place, string $expected): void
    {
        $suggestion = CourtAddressingSuggestionData::compose($division, ForumSource::PlaintiffAddress, $place, 'CPC, art. 46', 'Porque sim.', null);

        $this->assertSame($expected, $suggestion->courtAddressing);
        $this->assertLessThanOrEqual(255, mb_strlen((string) $suggestion->courtAddressing));
    }

    /**
     * Toda vara tem uma justiça, e é ela que escolhe o juiz e o território.
     */
    #[Test]
    public function every_court_belongs_to_a_branch_the_map_knows(): void
    {
        foreach (CourtDivision::cases() as $division) {
            $this->assertContains($division->branch(), [JusticeBranch::State, JusticeBranch::Federal, JusticeBranch::Labor]);
            $this->assertMatchesRegularExpression('/^(da|do) /', $division->withArticle());
        }
    }

    /**
     * A classe estreita o juízo pelo par justiça × grau: o procedimento comum
     * não vai ao juizado, o do juizado não vai à vara, e o que só tramita em
     * tribunal não tem juízo nenhum a oferecer.
     */
    #[Test]
    public function the_class_competences_narrow_the_courts(): void
    {
        $common = CourtDivision::allowedBy(['just_es_1grau', 'just_es_2grau', 'just_fed_1grau']);
        $smallClaims = CourtDivision::allowedBy(['just_es_juizado_es', 'just_fed_juizado_es']);

        $this->assertContains(CourtDivision::Civil, $common);
        $this->assertContains(CourtDivision::Federal, $common);
        $this->assertNotContains(CourtDivision::SmallClaims, $common);
        $this->assertNotContains(CourtDivision::Labor, $common);

        $this->assertContains(CourtDivision::SmallClaims, $smallClaims);
        $this->assertContains(CourtDivision::FederalSmallClaims, $smallClaims);
        $this->assertNotContains(CourtDivision::Civil, $smallClaims);

        $this->assertSame([CourtDivision::Labor], CourtDivision::allowedBy(['just_trab_1grau', 'just_trab_2grau']));
        $this->assertSame([], CourtDivision::allowedBy(['just_es_2grau', 'stj']));
        $this->assertSame(CourtDivision::cases(), CourtDivision::allowedBy([]));
    }

    /**
     * A cidade do relato só entra se o relato a escrever, sem acento nem caixa
     * no caminho — e a que ele não escreve sai com a UF, e com aviso.
     */
    #[Test]
    public function a_city_from_the_narrative_must_be_written_in_it(): void
    {
        $this->assertTrue(ForumPlace::writtenIn('São José', 'o acidente foi em sao jose, perto da ponte'));
        $this->assertTrue(ForumPlace::writtenIn('ITAJAÍ', 'O imóvel fica em Itajaí.'));
        $this->assertFalse(ForumPlace::writtenIn('Itá', 'O imóvel fica em Itajaí.'));

        $nowhere = new ForumPlace(null, null);
        $place = ForumPlace::resolve(ForumSource::Facts, 'Curitiba', BrazilianState::PR, $nowhere, $nowhere, 'O imóvel fica no litoral.');

        $this->assertNull($place->city);
        $this->assertNull($place->state);
        $this->assertSame('A IA apontou Curitiba, que o relato não menciona: confira a comarca do endereçamento.', $place->warning);
    }

    /**
     * Da ficha, a cidade é copiada, e a que o agente escreveu ao lado é
     * ignorada.
     */
    #[Test]
    public function a_city_from_a_record_is_copied_and_the_models_ignored(): void
    {
        $client = new ForumPlace('Joinville', BrazilianState::SC);
        $defendant = new ForumPlace('Porto Alegre', BrazilianState::RS);

        $this->assertSame($client, ForumPlace::resolve(ForumSource::PlaintiffAddress, 'Blumenau', BrazilianState::SP, $client, $defendant, ''));
        $this->assertSame($defendant, ForumPlace::resolve(ForumSource::DefendantAddress, 'Blumenau', BrazilianState::SP, $client, $defendant, ''));
    }

    /**
     * O envelope volta do navegador e da coluna como foi escrito, com o
     * sistema junto, e nada é recalculado.
     */
    #[Test]
    public function the_envelope_survives_a_round_trip(): void
    {
        $system = new JudicialSystem(['slug' => 'eproc', 'name' => 'eproc', 'position' => 1]);
        $system->id = '0199aaaa-0000-7000-8000-000000000001';

        $suggestion = CourtAddressingSuggestionData::compose(
            CourtDivision::Civil,
            ForumSource::PlaintiffAddress,
            new ForumPlace('Salvador', BrazilianState::BA),
            'CDC, art. 101, I',
            'O consumidor pode propor no próprio domicílio.',
            new JudicialSystemSelection($system, 'TJBA', AdoptionStatus::Transition, JudicialSystemSelection::FROM_MAP, null),
        );

        $envelope = $suggestion->toArray();

        $this->assertSame('eproc', $envelope['judicial_system']['slug']);
        $this->assertSame('Vara Cível', $envelope['division_label']);
        $this->assertSame(['O mapa registra o eproc no TJBA como “em transição”: confira no portal do tribunal se a comarca já o usa.'], $envelope['warnings']);
        $this->assertSame($envelope, CourtAddressingSuggestionData::fromArray($envelope)?->toArray());
        $this->assertNull(CourtAddressingSuggestionData::fromArray([]));
        $this->assertNull(CourtAddressingSuggestionData::fromArray(null));
    }

    /**
     * Sem juízo não há frase a compor, e a justificativa diz qual é o órgão.
     */
    #[Test]
    public function no_court_means_no_addressing(): void
    {
        $suggestion = CourtAddressingSuggestionData::compose(null, null, new ForumPlace(null, null), null, 'É competência originária do tribunal.', null);

        $this->assertNull($suggestion->courtAddressing);
        $this->assertSame([], $suggestion->warnings);
        $this->assertSame('É competência originária do tribunal.', $suggestion->justification);
    }
}
