<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\LegalCases\Data\DefendantData;
use App\Domain\LegalCases\Data\ForensicReviewData;
use App\Domain\LegalCases\Data\LegalCaseClassification;
use App\Domain\LegalCases\Data\LegalResearchData;
use App\Domain\LegalPrecedents\Data\LegalPrecedentData;
use App\Domain\LegalPrecedents\Enums\LegalPrecedentType;
use App\Domain\LegalTheses\Data\LegalBasisData;
use App\Domain\LegalTheses\Data\LegalThesisData;
use App\Domain\LegalTheses\Enums\LegalBasisType;
use App\Domain\LegalTheses\Enums\LegalThesisType;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use App\Domain\Requirements\Data\RequirementData;
use App\Domain\Requirements\Data\RequirementListData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The shape the classification travels in, without spending an inference on it.
 *
 * Built from real catalogue rows: the ids are the point of the payload, and a
 * fabricated model has none.
 */
final class LegalCaseClassificationPayloadTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_carries_both_entities_and_a_justification_each(): void
    {
        $area = PracticeArea::query()->where('slug', 'civil')->sole();
        $class = ProceduralClass::query()->where('code', 7)->sole();

        $payload = (new LegalCaseClassification(
            practiceArea: $area,
            practiceAreaJustification: 'O réu é um particular e não há relação de consumo.',
            proceduralClass: $class,
            proceduralClassJustification: 'O pedido é indenizatório e não há rito próprio.',
            defendant: new DefendantData(
                name: 'Joaquim Vizinho',
                document: '52998224725',
                email: null,
                phone: null,
                postalCode: null,
                street: null,
                number: null,
                complement: null,
                district: null,
                city: 'Joinville',
                state: BrazilianState::SC,
                notes: null,
            ),
            requirements: new RequirementListData([
                new RequirementData(
                    id: null,
                    description: 'A condenação do Réu à reconstrução do muro derrubado;',
                    amount: null,
                ),
                new RequirementData(
                    id: null,
                    description: 'A condenação do Réu ao pagamento de R$ 4.300,00 a título de danos materiais;',
                    amount: '4300.00',
                ),
            ]),
            research: self::research(),
        ))->toArray();

        $this->assertSame(
            [
                'practice_area',
                'practice_area_justification',
                'procedural_class',
                'procedural_class_justification',
                'defendant',
                'requirements',
                'research',
            ],
            array_keys($payload),
        );

        // The ids are here to be written to `legal_cases`, so they have to be
        // the rows' own — the slug and the CNJ code are what a human reads.
        $this->assertSame($area->id, $payload['practice_area']['id']);
        $this->assertSame('civil', $payload['practice_area']['slug']);
        $this->assertSame($class->id, $payload['procedural_class']['id']);
        $this->assertSame(7, $payload['procedural_class']['code']);
        $this->assertSame('Procedimento Comum Cível', $payload['procedural_class']['name']);

        // O réu não é achatado: viaja como as doze chaves `defendant_*` que a
        // etapa do réu preenche, e o nulo delas é "o relato não diz".
        $this->assertSame(array_keys((new DefendantData(
            name: null, document: null, email: null, phone: null,
            postalCode: null, street: null, number: null, complement: null,
            district: null, city: null, state: null, notes: null,
        ))->toArray()), array_keys($payload['defendant']));

        $this->assertSame('Joaquim Vizinho', $payload['defendant']['defendant_name']);
        $this->assertSame(BrazilianState::SC, $payload['defendant']['defendant_state']);
        $this->assertNull($payload['defendant']['defendant_email']);

        // Os pedidos viajam na ordem em que serão numerados, com a frase que
        // vai para a peça e o valor em decimal — nunca mascarado: quem
        // mascara é o campo que o desenha, e o id de cada linha é cunhado pelo
        // navegador, então não está aqui.
        $this->assertCount(2, $payload['requirements']);
        $this->assertSame(['description', 'amount'], array_keys($payload['requirements'][0]));
        $this->assertSame(
            'A condenação do Réu à reconstrução do muro derrubado;',
            $payload['requirements'][0]['description'],
        );
        $this->assertNull($payload['requirements'][0]['amount']);
        $this->assertSame('4300.00', $payload['requirements'][1]['amount']);

        // A revisão forense mantém a forma que LegalResearchData publica: o
        // relato da rodada por fora, e as duas listas achatadas por dentro.
        $this->assertSame(
            ['legal_question', 'theses', 'precedents', 'sources', 'pending', 'unverified_citations'],
            array_keys($payload['research']),
        );
    }

    /**
     * As duas listas viajam achatadas, e o vínculo entre elas é o
     * `legal_thesis_id`.
     *
     * É a forma que `SaveLegalCaseForensicReview` aceita, e não um detalhe de
     * serialização: o aninhamento existiu só na gramática do agente, para que o
     * modelo não precisasse cunhar uuid de correlação. O id da tese é cunhado em
     * PHP e é o que o precedente cita; o id do precedente é nulo, porque a chave
     * de uma linha nova é do banco.
     */
    #[Test]
    public function the_forensic_review_travels_flat_with_the_thesis_id_linking_the_two(): void
    {
        $payload = (new LegalCaseClassification(
            practiceArea: PracticeArea::query()->where('slug', 'civil')->sole(),
            practiceAreaJustification: 'O réu é um particular.',
            proceduralClass: ProceduralClass::query()->where('code', 7)->sole(),
            proceduralClassJustification: 'O pedido é indenizatório.',
            defendant: null,
            requirements: null,
            research: self::research(),
        ))->toArray();

        $this->assertCount(1, $payload['research']['theses']);
        $this->assertCount(1, $payload['research']['precedents']);

        $thesis = $payload['research']['theses'][0];
        $precedent = $payload['research']['precedents'][0];

        $this->assertSame(
            ['id', 'name', 'type', 'description', 'impact', 'legal_bases'],
            array_keys($thesis),
        );
        $this->assertSame(self::THESIS_ID, $thesis['id']);
        $this->assertSame(LegalThesisType::Preliminary, $thesis['type']);
        $this->assertSame('Súmula 430 do STJ', $thesis['legal_bases'][0]['reference']);

        // O fundamento guarda o valor do enum, e não o objeto: é o que vai para
        // a coluna jsonb, que é registro histórico e não se acopla a um enum
        // que ainda vai mudar.
        $this->assertSame('sumula', $thesis['legal_bases'][0]['type']);

        $this->assertSame(self::THESIS_ID, $precedent['legal_thesis_id']);
        $this->assertNull($precedent['id']);
        $this->assertSame('100.00', $precedent['adherence']);
    }

    /**
     * A pesquisa que caiu é o nulo, e a que nada confirmou são as listas vazias
     * com o pendente escrito. As duas abrem a etapa 6 sem tese nenhuma, e só a
     * segunda consegue dizer por quê.
     */
    #[Test]
    public function a_research_run_that_failed_is_null_and_one_that_confirmed_nothing_is_not(): void
    {
        $area = PracticeArea::query()->where('slug', 'civil')->sole();

        $failed = (new LegalCaseClassification(
            practiceArea: $area,
            practiceAreaJustification: 'O réu é um particular.',
            proceduralClass: null,
            proceduralClassJustification: null,
            defendant: null,
            requirements: null,
            research: null,
        ))->toArray();

        $this->assertArrayHasKey('research', $failed);
        $this->assertNull($failed['research']);

        $empty = (new LegalCaseClassification(
            practiceArea: $area,
            practiceAreaJustification: 'O réu é um particular.',
            proceduralClass: null,
            proceduralClassJustification: null,
            defendant: null,
            requirements: null,
            research: new LegalResearchData(
                legalQuestion: 'O relato sustenta alguma tese de direito do consumidor?',
                review: new ForensicReviewData(theses: [], precedents: []),
                pending: ['Nada se confirmou em fonte oficial para este relato.'],
            ),
        ))->toArray();

        $this->assertSame([], $empty['research']['theses']);
        $this->assertSame([], $empty['research']['precedents']);
        $this->assertSame(
            ['Nada se confirmou em fonte oficial para este relato.'],
            $empty['research']['pending'],
        );
    }

    /**
     * Uma pesquisa com um achado só, que é o bastante para a forma do payload.
     */
    private const THESIS_ID = '2168abf8-94ce-435b-b9b3-bab97bf30e77';

    private static function research(): LegalResearchData
    {
        return new LegalResearchData(
            legalQuestion: 'É cabível o redirecionamento da execução fiscal por mero inadimplemento?',
            review: new ForensicReviewData(
                theses: [
                    new LegalThesisData(
                        id: self::THESIS_ID,
                        name: 'Ilegitimidade passiva do sócio-administrador',
                        type: LegalThesisType::Preliminary,
                        description: 'O mero inadimplemento não autoriza a responsabilização pessoal.',
                        impact: 'Exclusão do Embargante do polo passivo.',
                        legalBases: [
                            new LegalBasisData(
                                type: LegalBasisType::Sumula,
                                reference: 'Súmula 430 do STJ',
                                source: 'STJ',
                            ),
                        ],
                    ),
                ],
                precedents: [
                    new LegalPrecedentData(
                        id: null,
                        thesisId: self::THESIS_ID,
                        name: 'STJ — Súmula nº 430',
                        type: LegalPrecedentType::Sumula,
                        description: 'O inadimplemento da obrigação tributária pela sociedade não gera, por si só, a responsabilidade solidária do sócio-gerente.',
                        citation: 'STJ. Primeira Seção. Súmula nº 430. Julgado em 24/03/2010.',
                        grounding: 'Impede o redirecionamento pretendido pelo Estado.',
                        adherence: '100.00',
                    ),
                ],
            ),
            sources: ['https://www.planalto.gov.br/ccivil_03/leis/l5172compilado.htm'],
        );
    }

    /**
     * Um relato que não pede nada é resposta legítima, e é a lista vazia —
     * diferente do nulo, que é a inferência que não aconteceu. A etapa abre
     * em branco nos dois casos; a distinção existe para o log.
     */
    #[Test]
    public function a_narrative_that_asks_for_nothing_is_an_empty_list_and_not_a_null(): void
    {
        $payload = (new LegalCaseClassification(
            practiceArea: PracticeArea::query()->where('slug', 'civil')->sole(),
            practiceAreaJustification: 'O réu é um particular.',
            proceduralClass: null,
            proceduralClassJustification: null,
            defendant: null,
            requirements: new RequirementListData([]),
            research: null,
        ))->toArray();

        $this->assertSame([], $payload['requirements']);
        $this->assertNull($payload['defendant']);
    }

    /**
     * Um réu que não pôde ser lido é diferente de um relato que não descreve
     * ninguém: o primeiro é este nulo, o segundo são doze nulos dentro do
     * objeto. A tela precisa das duas formas para abrir a etapa em branco sem
     * perder o enquadramento. Os pedidos carregam a mesma distinção, com a
     * lista vazia no lugar dos doze nulos.
     */
    #[Test]
    public function a_defendant_that_was_not_read_arrives_null_without_changing_the_shape(): void
    {
        $payload = (new LegalCaseClassification(
            practiceArea: PracticeArea::query()->where('slug', 'civil')->sole(),
            practiceAreaJustification: 'O réu é um particular.',
            proceduralClass: ProceduralClass::query()->where('code', 7)->sole(),
            proceduralClassJustification: 'O pedido é indenizatório.',
            defendant: null,
            requirements: null,
            research: null,
        ))->toArray();

        $this->assertArrayHasKey('defendant', $payload);
        $this->assertNull($payload['defendant']);

        $this->assertArrayHasKey('requirements', $payload);
        $this->assertNull($payload['requirements']);
    }

    #[Test]
    public function a_missing_class_empties_its_half_without_changing_the_shape(): void
    {
        $area = PracticeArea::query()->where('slug', 'civil')->sole();

        $payload = (new LegalCaseClassification(
            practiceArea: $area,
            practiceAreaJustification: 'O réu é um particular.',
            proceduralClass: null,
            proceduralClassJustification: null,
            defendant: null,
            requirements: null,
            research: null,
        ))->toArray();

        $this->assertNull($payload['procedural_class']);
        $this->assertNull($payload['procedural_class_justification']);
        $this->assertSame('civil', $payload['practice_area']['slug']);
    }
}
