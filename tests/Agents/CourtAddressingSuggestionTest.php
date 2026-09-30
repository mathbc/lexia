<?php

declare(strict_types=1);

namespace Tests\Agents;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Customers\Models\Customer;
use App\Domain\LegalCases\Actions\SuggestCourtAddressing;
use App\Domain\LegalCases\Data\DefendantData;
use App\Domain\LegalCases\Enums\CourtDivision;
use App\Domain\LegalCases\Enums\ForumSource;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The addressing chain against the real text provider, no fakes.
 *
 * RefreshDatabase for the catalogue and the map — the class travels with its
 * competences, and the judicial system is read off `judicial_system_courts` —
 * and for the one client each case needs.
 *
 * Six narratives, each the textbook case of one rule, and the court and the
 * source of the city are held firmly: a consumer suing a bank at home (CDC,
 * art. 101, I), an eviction where the flat is (Lei 8.245, art. 58, II), child
 * support at the child's home (CPC, art. 53, II), a benefit the INSS denied in
 * the federal small-claims court, a labour claim where the work was done (CLT,
 * art. 651), and a divorce at the guardian's home (CPC, art. 53, I, a). An
 * agent that sends any of them to the defendant's domicile out of habit is
 * wrong, not varying — it is the easy exit the knowledge guide names first.
 *
 * What is held loosely is the justification's wording. What is held firmly
 * besides the answer is what the prompt forbids: no súmula in the legal basis,
 * and no client name anywhere — it is not even sent.
 *
 *   composer test:agents
 */
#[Group('agents')]
final class CourtAddressingSuggestionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, int, string, array{string, BrazilianState, int|null}, array<string, string>, list<CourtDivision>, list<ForumSource>, string}>
     */
    public static function narratives(): array
    {
        return [
            'o consumidor contra o banco' => [
                'consumidor',
                7,
                <<<'TXT'
                O Banco Horizonte lançou no meu cartão de crédito doze parcelas de um seguro que eu nunca
                contratei, R$ 89,90 por mês. Liguei no SAC, abri reclamação no Procon e eles não estornam.
                Quero o dinheiro de volta em dobro e uma indenização pelo tempo perdido.
                TXT,
                ['Joinville', BrazilianState::SC, 45],
                ['defendant_name' => 'Banco Horizonte S.A.', 'defendant_document' => '11222333000144', 'defendant_city' => 'São Paulo', 'defendant_state' => 'SP'],
                [CourtDivision::Civil],
                [ForumSource::PlaintiffAddress],
                'Joinville',
            ],
            'o despejo onde fica o imóvel' => [
                'imobiliario',
                92,
                <<<'TXT'
                Sou dono de um apartamento em Balneário Camboriú, na Avenida Brasil, que aluguei em 2023 por
                contrato escrito de trinta meses. O inquilino não paga o aluguel há quatro meses e não atende
                mais o telefone. Quero o imóvel de volta e os aluguéis atrasados.
                TXT,
                ['Curitiba', BrazilianState::PR, 61],
                ['defendant_name' => 'Rogério Antunes'],
                [CourtDivision::Civil],
                [ForumSource::Facts],
                'Balneário Camboriú',
            ],
            'os alimentos na casa do alimentando' => [
                'familia-sucessoes',
                69,
                <<<'TXT'
                Meu filho tem 6 anos e mora comigo. O pai dele se mudou para Porto Alegre há dois anos, tem
                emprego fixo numa transportadora e não paga nada desde então. Quero pedir pensão para o menino.
                TXT,
                ['Blumenau', BrazilianState::SC, 32],
                ['defendant_name' => 'Marcos Vieira', 'defendant_city' => 'Porto Alegre', 'defendant_state' => 'RS'],
                [CourtDivision::Family],
                [ForumSource::PlaintiffAddress],
                'Blumenau',
            ],
            'o benefício que o INSS negou' => [
                'previdenciario',
                436,
                <<<'TXT'
                Trabalho como pedreiro há 30 anos e tenho hérnia de disco na lombar, com laudo do ortopedista
                dizendo que não posso carregar peso. Pedi o auxílio por incapacidade no INSS em março e a
                perícia negou, dizendo que estou apto. Estou sem trabalhar e sem renda desde então.
                TXT,
                ['Chapecó', BrazilianState::SC, 58],
                ['defendant_name' => 'Instituto Nacional do Seguro Social — INSS', 'defendant_document' => '29979036000140', 'defendant_city' => 'Brasília', 'defendant_state' => 'DF'],
                [CourtDivision::FederalSmallClaims],
                [ForumSource::PlaintiffAddress],
                'Chapecó',
            ],
            'a reclamação onde o trabalho foi prestado' => [
                'trabalhista',
                985,
                <<<'TXT'
                Trabalhei quatro anos como conferente no depósito da Transportes Rota Sul em Itajaí, de segunda
                a sábado, das 6h às 18h, sem nunca receber hora extra. Fui mandado embora em agosto sem receber
                a rescisão nem as guias do seguro-desemprego.
                TXT,
                ['Itajaí', BrazilianState::SC, 39],
                ['defendant_name' => 'Transportes Rota Sul Ltda', 'defendant_document' => '44555666000177', 'defendant_city' => 'São Paulo', 'defendant_state' => 'SP'],
                [CourtDivision::Labor],
                [ForumSource::Facts, ForumSource::PlaintiffAddress],
                'Itajaí',
            ],
            'o divórcio na casa de quem tem a guarda' => [
                'familia-sucessoes',
                12541,
                <<<'TXT'
                Fui casada por doze anos e estamos separados há um ano. Nossas duas filhas, de 8 e 11 anos, moram
                comigo desde a separação. Ele foi morar em Curitiba e não aceita assinar o divórcio no cartório.
                Quero o divórcio, a guarda das meninas e a pensão.
                TXT,
                ['Florianópolis', BrazilianState::SC, 41],
                ['defendant_name' => 'Paulo Henrique Duarte', 'defendant_city' => 'Curitiba', 'defendant_state' => 'PR'],
                [CourtDivision::Family],
                [ForumSource::PlaintiffAddress],
                'Florianópolis',
            ],
        ];
    }

    /**
     * @param  array{string, BrazilianState, int|null}  $client
     * @param  array<string, string>  $defendant
     * @param  list<CourtDivision>  $divisions
     * @param  list<ForumSource>  $sources
     */
    #[Test]
    #[DataProvider('narratives')]
    public function it_places_the_forum_the_way_the_rules_do(
        string $areaSlug,
        int $classCode,
        string $facts,
        array $client,
        array $defendant,
        array $divisions,
        array $sources,
        string $city,
    ): void {
        [$clientCity, $clientState, $age] = $client;

        $customer = Customer::factory()->create([
            'name' => 'Cliente Sigiloso da Silva',
            'city' => $clientCity,
            'state' => $clientState,
            'birth_date' => $age === null ? null : now()->subYears($age)->subMonth(),
        ]);

        $suggestion = SuggestCourtAddressing::run(
            PracticeArea::query()->where('slug', $areaSlug)->sole(),
            ProceduralClass::query()->where('code', $classCode)->sole(),
            $facts,
            $customer,
            DefendantData::fromArray($defendant),
        );

        // The point of this test is also to look at the answer, and PHPUnit
        // swallows stdout — STDERR is what actually reaches the terminal.
        fwrite(STDERR, PHP_EOL.json_encode([
            'fatos' => $facts,
            'resposta' => $suggestion->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL.PHP_EOL);

        $this->assertContains($suggestion->division, $divisions);
        $this->assertContains($suggestion->forumSource, $sources);
        $this->assertSame($city, $suggestion->city);
        $this->assertStringStartsWith('Excelentíssimo(a) Senhor(a) Juiz(a)', (string) $suggestion->courtAddressing);
        $this->assertNotSame('', $suggestion->justification);

        $this->assertDoesNotMatchRegularExpression('/s[úu]mula|\btema\b/iu', (string) $suggestion->legalBasis);
        $this->assertStringNotContainsString('Sigiloso', $suggestion->justification);
    }
}
