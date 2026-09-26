<?php

declare(strict_types=1);

namespace Tests\Agents;

use App\Ai\Agents\PleadingGroundsReinforcementAgent;
use App\Domain\CourtDecisions\Models\CourtDecision;
use App\Domain\LegalCases\Data\ReinforcedGroundsData;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalCases\Support\LegalCaseDossier;
use App\Domain\LegalTheses\Enums\LegalThesisType;
use App\Domain\LegalTheses\Models\LegalThesis;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use App\Domain\Requirements\Models\Requirement;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Responses\StructuredAgentResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The agent that reinforces the DO DIREITO of a drafted pleading.
 *
 * The body it is handed is fixed here rather than drafted by the sibling agent,
 * so what is measured is this agent alone: a thin section — each thesis stated,
 * none argued — and two theses of different kinds to reinforce it with.
 *
 * What is pinned follows the sibling tests: wording is judgement, structure and
 * invention are not. Pinned are the refusals ReinforcedGroundsData would turn
 * into a fallback (a ruling lost or repeated, a súmula or a figure nobody
 * wrote), the thesis subtitles kept in their order, and the one phrase the
 * instructions demand outright — a "Mérito subsidiário" opened with
 * "subsidiariamente", so the pleading does not argue two incompatible positions
 * as one.
 *
 * Not measured against any model yet. Run it, read what comes out of `show()`,
 * and rewrite these notes with what the configured model actually does.
 *
 *   composer test:agents
 */
#[Group('agents')]
final class PleadingGroundsReinforcementTest extends TestCase
{
    private const string FACTS = <<<'TXT'
    Doutor, no dia 10 de setembro de 2026, por volta das 21h, um carro bateu no meu
    muro. O motorista, o Pedro Henrique, tava visivelmente bêbado e fugiu. O carro era
    do pai dele, o Carlos. O muro caiu e o portão entortou. A polícia fez o boletim e
    as vizinhas viram tudo. Os orçamentos que peguei pro conserto deram R$ 18.400,00.
    TXT;

    private const string PRINCIPAL = 'Da Responsabilidade Civil por Ato Ilícito';

    private const string SUBSIDIARY = 'Da Responsabilidade Solidária do Proprietário do Veículo';

    #[Test]
    public function it_argues_each_thesis_without_losing_a_ruling_or_inventing_a_citation(): void
    {
        $body = implode("\n\n", [
            self::PRINCIPAL,
            'O Réu causou danos ao Autor e deve indenizá-lo, nos termos dos arts. 186 e 927 do Código Civil.',
            self::SUBSIDIARY,
            'O proprietário do veículo também responde pelos danos, nos termos do art. 932, III, do Código Civil.',
            'Nesse sentido, é o entendimento do Superior Tribunal de Justiça:',
            '[[JULGADO 1]]',
        ]);

        $legalCase = $this->pleading();
        $dossier = LegalCaseDossier::forGrounds($legalCase);

        $response = (new PleadingGroundsReinforcementAgent(
            dossier: $dossier,
            facts: self::FACTS,
        ))->prompt($body);

        $this->assertInstanceOf(StructuredAgentResponse::class, $response);

        $reinforced = ReinforcedGroundsData::fromAgent($response->toArray(), $body, $dossier.' '.self::FACTS);

        $this->show($body, $reinforced);

        // O que a Action recusaria — e, recusando, devolveria a seção original.
        $this->assertNull($reinforced->refusal());

        // Os subtítulos das teses, na mesma grafia e na mesma ordem.
        $principal = mb_strpos($reinforced->content, self::PRINCIPAL);
        $subsidiary = mb_strpos($reinforced->content, self::SUBSIDIARY);
        $this->assertNotFalse($principal);
        $this->assertNotFalse($subsidiary);
        $this->assertLessThan($subsidiary, $principal);

        // O julgado continua no fim da tese que ele corrobora.
        $this->assertGreaterThan($subsidiary, mb_strpos($reinforced->content, '[[JULGADO 1]]'));

        // A espécie da tese muda a redação: o subsidiário é aberto como tal.
        $this->assertStringContainsStringIgnoringCase('subsidiariamente', $reinforced->content);

        // O agente devolve o corpo, e nada acima nem abaixo dele.
        $this->assertStringNotContainsString('DO DIREITO', $reinforced->content);
        $this->assertStringNotContainsString('DOS PEDIDOS', $reinforced->content);
        $this->assertDoesNotMatchRegularExpression('/^\s*[IVX]+\s*[–—-]/mu', $reinforced->content);

        // Nenhum fato que o relato não conta — a velocidade é o palpite óbvio.
        $this->assertDoesNotMatchRegularExpression('/\d+\s*km\/h/u', $reinforced->content);
        $this->assertDoesNotMatchRegularExpression('/\d{7}-\d{2}\.\d{4}\.\d\.\d{2}\.\d{4}/u', $reinforced->content);
    }

    /**
     * Uma peça que existe só em memória, com as relações que `forGrounds()` lê.
     */
    private function pleading(): LegalCase
    {
        $legalCase = new LegalCase(['facts' => self::FACTS]);

        $legalCase->setRelation('practiceArea', new PracticeArea(['slug' => 'civil', 'label' => 'Direito Civil']));
        $legalCase->setRelation('proceduralClass', new ProceduralClass(['code' => 7, 'name' => 'Procedimento Comum Cível']));

        $legalCase->setRelation('requirements', new Collection([
            new Requirement(['description' => 'A condenação dos Réus ao pagamento dos danos materiais;', 'amount' => '18400.00']),
        ]));

        $legalCase->setRelation('theses', new Collection([
            new LegalThesis([
                'name' => self::PRINCIPAL,
                'type' => LegalThesisType::PrincipalMerits,
                'description' => 'A conduta culposa do condutor — dirigir sob efeito de álcool e colidir contra propriedade alheia — gera o dever de indenizar os danos dela decorrentes.',
                'impact' => 'Assegura a reparação integral do prejuízo material.',
                'legal_bases' => [
                    ['type' => 'article', 'reference' => 'Art. 186 do Código Civil', 'source' => 'CC'],
                    ['type' => 'article', 'reference' => 'Art. 927 do Código Civil', 'source' => 'CC'],
                ],
            ]),
            new LegalThesis([
                'name' => self::SUBSIDIARY,
                'type' => LegalThesisType::SubsidiaryMerits,
                'description' => 'Caso não se alcance o patrimônio do condutor, o proprietário do veículo responde solidariamente pelos danos causados por quem o conduzia com o seu consentimento.',
                'impact' => 'Garante a reparação ainda que o condutor não tenha patrimônio.',
                'legal_bases' => [
                    ['type' => 'article', 'reference' => 'Art. 932, III, do Código Civil', 'source' => 'CC'],
                ],
            ]),
        ]));

        $legalCase->setRelation('courtDecisions', new Collection([
            new CourtDecision([
                'title' => 'AgInt no REsp 2091428 / MA',
                'locality' => 'Brasil',
                'authority' => 'Superior Tribunal de Justiça. 3ª Turma',
                'summary' => 'PROCESSUAL CIVIL. AGRAVO INTERNO NO RECURSO ESPECIAL. ACIDENTE DE TRÂNSITO. PROPRIETÁRIO DO VEÍCULO. RESPONSABILIDADE SOLIDÁRIA. 1. O proprietário do veículo responde solidariamente pelos danos decorrentes de acidente de trânsito causado por culpa do condutor. 2. Agravo interno não provido.',
                'decided_at' => '2023-11-13',
            ]),
        ]));

        return $legalCase;
    }

    /**
     * PHPUnit swallows stdout — STDERR is what reaches the terminal.
     */
    private function show(string $body, ReinforcedGroundsData $reinforced): void
    {
        fwrite(STDERR, PHP_EOL.json_encode([
            'recebido' => $body,
            'devolvido' => $reinforced->content,
            'recusa' => $reinforced->refusal(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL.PHP_EOL);
    }
}
