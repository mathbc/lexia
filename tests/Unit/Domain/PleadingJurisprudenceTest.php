<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\CourtDecisions\Models\CourtDecision;
use App\Domain\LegalPleadings\Support\PleadingBlocks;
use App\Domain\LegalPleadings\Support\PleadingJurisprudence;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A citação de um julgado na minuta, montada em PHP a partir do registro.
 *
 * O agente escreve só o marcador; o que fica no lugar dele é decidido aqui, e
 * o que se fixa são as três coisas que o modelo não pode estragar: a ementa
 * copiada palavra por palavra, a referência composta das colunas, e o recuo —
 * que é o `>` que `PleadingBlocks` lê, e portanto o mesmo na tela, no PDF e no
 * DOCX.
 *
 * Sem `RefreshDatabase`: os julgados são montados em memória.
 */
final class PleadingJurisprudenceTest extends TestCase
{
    private const string EMENTA = 'PROCESSUAL CIVIL. AGRAVO INTERNO NO RECURSO ESPECIAL. ACIDENTE DE TRÂNSITO. PROPRIETÁRIO DO VEÍCULO. RESPONSABILIDADE SOLIDÁRIA. 1. O proprietário do veículo responde solidariamente pelos danos decorrentes de acidente de trânsito causado por culpa do condutor. 2. Agravo interno não provido.';

    #[Test]
    public function the_reference_is_composed_from_the_record(): void
    {
        $this->assertSame(
            'BRASIL. Superior Tribunal de Justiça. AgInt no REsp 2091428 / MA. 3ª Turma, julgado em 13/11/2023.',
            PleadingJurisprudence::reference($this->decision()),
        );

        // A jurisdição de um tribunal estadual é o estado, e não o país.
        $this->assertSame(
            'DISTRITO FEDERAL. Tribunal de Justiça do Distrito Federal e dos Territórios. Acórdão nº 378637. 5ª Turma Cível, julgado em 23/09/2009.',
            PleadingJurisprudence::reference($this->decision([
                'title' => 'Acórdão nº 378637',
                'locality' => 'Distrito Federal',
                'authority' => 'Tribunal de Justiça do Distrito Federal e dos Territórios. 5ª Turma Cível',
                'decided_at' => '2009-09-23',
            ])),
        );
    }

    /**
     * O registro às vezes não traz órgão, data ou localidade, e a referência
     * para onde ele para — sem vírgula órfã, sem ponto duplo e sem inventar.
     */
    #[Test]
    public function the_reference_stops_where_the_record_does(): void
    {
        $this->assertSame(
            'Supremo Tribunal Federal. ACO 342.',
            PleadingJurisprudence::reference($this->decision([
                'title' => 'ACO 342.',
                'locality' => null,
                'authority' => 'Supremo Tribunal Federal',
                'decided_at' => null,
            ])),
        );
    }

    /**
     * O campo Ementa do LexML traz a certidão do julgamento colada ao fim, às
     * vezes com o til perdido. A petição cita a ementa, e só ela.
     */
    #[Test]
    public function the_quoted_ementa_leaves_the_judgment_record_behind(): void
    {
        $summary = self::EMENTA.' Decis?o Vistos e relatados estes autos em que são partes as acima indicadas, acordam os Ministros da TERCEIRA TURMA.';

        $this->assertSame(self::EMENTA, PleadingJurisprudence::ementa($summary));
        $this->assertSame(self::EMENTA, PleadingJurisprudence::ementa(str_replace('Decis?o', 'Decisão', $summary)));

        // As quebras de linha do registro viram espaço: a ementa é um parágrafo só.
        $this->assertSame('UM. DOIS.', PleadingJurisprudence::ementa("UM.\n\n  DOIS."));
    }

    /**
     * O caso para o qual a classe existe: o marcador que o agente deixou vira a
     * ementa e a referência, e os dois parágrafos são citação recuada.
     */
    #[Test]
    public function a_marker_alone_in_its_paragraph_becomes_two_indented_paragraphs(): void
    {
        $content = "III – DO DIREITO\n\nNesse sentido, é o entendimento do Superior Tribunal de Justiça:\n\n[[JULGADO 1]]\n\nIV – DOS PEDIDOS";

        $expanded = PleadingJurisprudence::expand($content, new Collection([$this->decision()]));

        $this->assertStringNotContainsString('[[JULGADO', $expanded);

        $this->assertSame([
            ['lines' => ['III – DO DIREITO'], 'citation' => false],
            ['lines' => ['Nesse sentido, é o entendimento do Superior Tribunal de Justiça:'], 'citation' => false],
            ['lines' => ['"'.self::EMENTA.'"'], 'citation' => true],
            ['lines' => ['(BRASIL. Superior Tribunal de Justiça. AgInt no REsp 2091428 / MA. 3ª Turma, julgado em 13/11/2023.)'], 'citation' => true],
            ['lines' => ['IV – DOS PEDIDOS'], 'citation' => false],
        ], PleadingBlocks::of($expanded));

        // Uma linha em branco de cada lado, e não três: o texto é o que o
        // advogado continua editando.
        $this->assertStringNotContainsString("\n\n\n", $expanded);
    }

    /**
     * O que um modelo varia sem mudar o sentido: caixa, espaços, um `>` na
     * frente e a falta da linha em branco antes do marcador. O bloco cai num
     * parágrafo próprio mesmo assim — é nele que o recuo se aplica.
     */
    #[Test]
    public function a_marker_written_loosely_still_lands_in_a_paragraph_of_its_own(): void
    {
        $decisions = new Collection([
            $this->decision(),
            $this->decision(['title' => 'REsp 1106086 / MA', 'summary' => 'SEGUNDA EMENTA.']),
        ]);

        $expanded = PleadingJurisprudence::expand(
            "No mesmo sentido:\n  > [[ julgado 2 ]]  \nIV – DOS PEDIDOS",
            $decisions,
        );

        $blocks = PleadingBlocks::of($expanded);

        $this->assertSame(['No mesmo sentido:'], $blocks[0]['lines']);
        $this->assertSame([['"SEGUNDA EMENTA."'], true], [$blocks[1]['lines'], $blocks[1]['citation']]);
        $this->assertStringContainsString('REsp 1106086 / MA', $blocks[2]['lines'][0]);
        $this->assertSame(['IV – DOS PEDIDOS'], $blocks[3]['lines']);
    }

    /**
     * O marcador no meio da frase e o que aponta para um julgado que o dossiê
     * não traz ficam como estão: colchete duplo que o advogado vê e que conta
     * como lacuna — que é a leitura honesta dos dois.
     */
    #[Test]
    public function a_marker_inside_a_sentence_or_beyond_the_list_is_left_alone(): void
    {
        $content = "Conforme [[JULGADO 1]], o dano é devido.\n\n[[JULGADO 2]]";

        $this->assertSame($content, PleadingJurisprudence::expand($content, new Collection([$this->decision()])));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function decision(array $attributes = []): CourtDecision
    {
        return new CourtDecision([
            'title' => 'AgInt no REsp 2091428 / MA',
            'locality' => 'Brasil',
            'authority' => 'Superior Tribunal de Justiça. 3ª Turma',
            'summary' => self::EMENTA,
            'source_url' => 'https://www.lexml.gov.br/urn/urn:lex:br:superior.tribunal.justica;turma.3:acordao;resp:2023-11-13;2091428-2368856',
            'decided_at' => '2023-11-13',
            ...$attributes,
        ]);
    }
}
