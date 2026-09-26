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
 * A abreviação é a quarta coisa, e segue a mesma regra: o modelo escolhe os
 * trechos **por número**, e o que entra na citação é o texto do registro — o
 * cabeçalho entra sempre, e cada corte é marcado com `[...]`.
 *
 * Sem `RefreshDatabase`: os julgados são montados em memória.
 */
final class PleadingJurisprudenceTest extends TestCase
{
    /**
     * Ementas reais do LexML, nas três formas em que os tribunais as publicam —
     * as mesmas da CourtDecisionFactory.
     */
    private const string STJ = 'PROCESSUAL CIVIL. EMBARGOS À EXECUÇÃO. PENHORA. NATUREZA IMPENHORÁVEL DOS BENS PÚBLICOS. CABIMENTO. - A lei processual civil, no título que trata dos embargos do devedor, disciplina os embargos à execução fundada em sentença, os embargos à execução fundada em título extrajudicial, os embargos à arrematação e à adjudicação e os embargos à execução por carta, não fazendo menção a qualquer espécie processual sob a denominação de embargos à penhora. - Em sede de execução de sentença, insurgindo-se o devedor contra a penhora imposta sobre seus bens, deve fazê-lo por meio do instrumento processual nominado dos embargos à execução, sendo irrelevante a circunstância de se tratarem de bens impenhoráveis de entidade pública. - Recurso especial conhecido.';

    private const string TJDFT = 'PROCESSO CIVIL. EMBARGOS DE DECLARAÇÃO. LOSANGO PROMOTORA DE VENDAS. EXECUÇÃO DE VERBA HONORÁRIA. TEMPESTIVIDADE DOS EMBARGOS À EXECUÇÃO. OMISSÃO. SUPRIMENTO. 1. SE ANTES DA EFETIVAÇÃO DA PENHORA NÃO HOUVE QUALQUER CONSTRIÇÃO DE BENS VÁLIDA, É DE SE CONSIDERAR COMO TERMO A QUO, PARA OFERECIMENTO DOS EMBARGOS À EXECUÇÃO, A DATA DA INTIMAÇÃO DA PENHORA, REPRESENTADA PELO DEPÓSITO JUDICIAL APRESENTADO EM CARTÓRIO. 2. LAVRADO O TERMO DE PENHORA, E CERTIFICADO NOS AUTOS A INTIMAÇÃO DO DEVEDOR, É DE SE REPUTAR TEMPESTIVOS OS EMBARGOS À EXECUÇÃO AJUIZADOS EM DATA POSTERIOR À SEGURANÇA DO JUÍZO, PORÉM, EM DATA ANTERIOR À INEQUÍVOCA INTIMAÇÃO DA CONSTRIÇÃO. 3. RECURSO PROVIDO PARA SUPRIMENTO DA OMISSÃO, AFIRMANDO-SE TEMPESTIVOS OS EMBARGOS À EXECUÇÃO MANEJADOS, SEM ALTERAÇÃO DO MÉRITO DO ACÓRDÃO GUERREADO.';

    private const string ALL_CAPS = '- EMBARGOS A EXECUÇÃO JULGADOS PROCEDENTES. AÇÃO ANULATORIA DE PARCIAL ANULAÇÃO DE DECRETO CONCESSIVO DE AFORAMENTO JULGADA IMPROCEDENTE. EXECUÇÃO LIMITADA AOS HONORARIOS E DESPESAS PROCESSUAIS.';

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
     * O cabeçalho é o que abre a ementa em caixa alta, até o primeiro item — e
     * não existe quando a ementa inteira é caixa alta sem item nenhum.
     */
    #[Test]
    public function the_heading_is_the_block_capitals_before_the_first_item(): void
    {
        $this->assertSame(
            'PROCESSUAL CIVIL. EMBARGOS À EXECUÇÃO. PENHORA. NATUREZA IMPENHORÁVEL DOS BENS PÚBLICOS. CABIMENTO.',
            PleadingJurisprudence::heading(self::STJ),
        );

        // Tudo em caixa alta: é o "1." que diz onde o cabeçalho acaba.
        $this->assertSame(
            'PROCESSO CIVIL. EMBARGOS DE DECLARAÇÃO. LOSANGO PROMOTORA DE VENDAS. EXECUÇÃO DE VERBA HONORÁRIA. TEMPESTIVIDADE DOS EMBARGOS À EXECUÇÃO. OMISSÃO. SUPRIMENTO.',
            PleadingJurisprudence::heading(self::TJDFT),
        );

        $this->assertSame('', PleadingJurisprudence::heading(self::ALL_CAPS));
    }

    /**
     * Os trechos que o dossiê numera: o cabeçalho primeiro, depois cada frase
     * ou item — e, juntos por um espaço, a ementa de novo, que é o que deixa a
     * citação ser montada sem uma palavra que não esteja no registro.
     */
    #[Test]
    public function the_ementa_is_cut_into_the_heading_and_then_each_item(): void
    {
        $this->assertSame([
            'PROCESSO CIVIL. EMBARGOS DE DECLARAÇÃO. LOSANGO PROMOTORA DE VENDAS. EXECUÇÃO DE VERBA HONORÁRIA. TEMPESTIVIDADE DOS EMBARGOS À EXECUÇÃO. OMISSÃO. SUPRIMENTO.',
            '1. SE ANTES DA EFETIVAÇÃO DA PENHORA NÃO HOUVE QUALQUER CONSTRIÇÃO DE BENS VÁLIDA, É DE SE CONSIDERAR COMO TERMO A QUO, PARA OFERECIMENTO DOS EMBARGOS À EXECUÇÃO, A DATA DA INTIMAÇÃO DA PENHORA, REPRESENTADA PELO DEPÓSITO JUDICIAL APRESENTADO EM CARTÓRIO.',
            '2. LAVRADO O TERMO DE PENHORA, E CERTIFICADO NOS AUTOS A INTIMAÇÃO DO DEVEDOR, É DE SE REPUTAR TEMPESTIVOS OS EMBARGOS À EXECUÇÃO AJUIZADOS EM DATA POSTERIOR À SEGURANÇA DO JUÍZO, PORÉM, EM DATA ANTERIOR À INEQUÍVOCA INTIMAÇÃO DA CONSTRIÇÃO.',
            '3. RECURSO PROVIDO PARA SUPRIMENTO DA OMISSÃO, AFIRMANDO-SE TEMPESTIVOS OS EMBARGOS À EXECUÇÃO MANEJADOS, SEM ALTERAÇÃO DO MÉRITO DO ACÓRDÃO GUERREADO.',
        ], PleadingJurisprudence::passages(self::TJDFT));

        // O travessão é item também, e a abreviatura não é fim de frase.
        $this->assertSame([
            'PROCESSUAL CIVIL. EMBARGOS À EXECUÇÃO.',
            '- A penhora se impugna por embargos, nos termos do art. 16 da Lei n. 6.830/80.',
            '- Recurso especial conhecido.',
        ], PleadingJurisprudence::passages('PROCESSUAL CIVIL. EMBARGOS À EXECUÇÃO. - A penhora se impugna por embargos, nos termos do art. 16 da Lei n. 6.830/80. - Recurso especial conhecido.'));

        foreach ([self::STJ, self::TJDFT, self::ALL_CAPS, self::EMENTA] as $ementa) {
            $this->assertSame($ementa, implode(' ', PleadingJurisprudence::passages($ementa)));
        }
    }

    /**
     * O caso para o qual a abreviação existe: o trecho que o agente escolheu,
     * depois do cabeçalho — que entra sem ter sido escolhido —, com `[...]` onde
     * o texto do tribunal foi cortado.
     */
    #[Test]
    public function an_abridged_ementa_keeps_the_heading_and_marks_every_cut(): void
    {
        $this->assertSame(
            'PROCESSUAL CIVIL. EMBARGOS À EXECUÇÃO. PENHORA. NATUREZA IMPENHORÁVEL DOS BENS PÚBLICOS. CABIMENTO. [...] - Em sede de execução de sentença, insurgindo-se o devedor contra a penhora imposta sobre seus bens, deve fazê-lo por meio do instrumento processual nominado dos embargos à execução, sendo irrelevante a circunstância de se tratarem de bens impenhoráveis de entidade pública. [...]',
            PleadingJurisprudence::abridge(self::STJ, [3]),
        );
    }

    /**
     * Os trechos voltam na ordem do tribunal, repetidos contam uma vez, e os
     * vizinhos não ganham marca de supressão entre si.
     */
    #[Test]
    public function passages_are_put_back_in_the_courts_order(): void
    {
        $passages = PleadingJurisprudence::passages(self::TJDFT);

        $this->assertSame(
            implode(' ', [$passages[0], $passages[1], '[...]', $passages[3]]),
            PleadingJurisprudence::abridge(self::TJDFT, [4, 2, 4]),
        );

        $this->assertSame(
            implode(' ', [$passages[0], $passages[1], $passages[2], '[...]']),
            PleadingJurisprudence::abridge(self::TJDFT, [2, 3]),
        );
    }

    /**
     * Número que não nomeia trecho é ignorado. Sem nenhum que nomeie, a ementa
     * vai inteira — que é o que ela fazia antes da abreviação.
     */
    #[Test]
    public function a_number_that_names_no_passage_is_ignored(): void
    {
        $this->assertSame(self::STJ, PleadingJurisprudence::abridge(self::STJ, []));
        $this->assertSame(self::STJ, PleadingJurisprudence::abridge(self::STJ, [0, 99]));

        $this->assertSame(
            PleadingJurisprudence::heading(self::STJ).' [...] - Recurso especial conhecido.',
            PleadingJurisprudence::abridge(self::STJ, [99, 4]),
        );
    }

    /**
     * Escolher tudo é não abreviar; e a ementa sem cabeçalho — toda em caixa
     * alta, sem item — abrevia sem forçar trecho nenhum.
     */
    #[Test]
    public function choosing_every_passage_quotes_the_ementa_whole(): void
    {
        $this->assertSame(self::STJ, PleadingJurisprudence::abridge(self::STJ, [1, 2, 3, 4]));

        $this->assertSame(
            '- EMBARGOS A EXECUÇÃO JULGADOS PROCEDENTES. [...]',
            PleadingJurisprudence::abridge(self::ALL_CAPS, [1]),
        );
    }

    /**
     * Da resposta do agente ao documento: o marcador vira a ementa abreviada e
     * a referência — que continua inteira, com o título e a data do julgamento,
     * seja qual for a escolha.
     */
    #[Test]
    public function the_expansion_quotes_the_passages_chosen_for_each_marker(): void
    {
        $expanded = PleadingJurisprudence::expand(
            "Nesse sentido:\n\n[[JULGADO 1]]\n\nIV – DOS PEDIDOS",
            new Collection([$this->decision()]),
            [1 => [2]],
        );

        $citations = array_values(array_filter(PleadingBlocks::of($expanded), static fn (array $block): bool => $block['citation']));

        $this->assertSame([
            ['"PROCESSUAL CIVIL. AGRAVO INTERNO NO RECURSO ESPECIAL. ACIDENTE DE TRÂNSITO. PROPRIETÁRIO DO VEÍCULO. RESPONSABILIDADE SOLIDÁRIA. 1. O proprietário do veículo responde solidariamente pelos danos decorrentes de acidente de trânsito causado por culpa do condutor. [...]"'],
            ['(BRASIL. Superior Tribunal de Justiça. AgInt no REsp 2091428 / MA. 3ª Turma, julgado em 13/11/2023.)'],
        ], array_column($citations, 'lines'));
    }

    /**
     * Os marcadores sozinhos na linha, que a guarda do embasamento compara
     * antes e depois; o do meio da frase não conta, porque não seria expandido.
     */
    #[Test]
    public function it_reads_the_markers_standing_alone(): void
    {
        $this->assertSame(
            [1, 2, 2],
            PleadingJurisprudence::markersIn("[[JULGADO 2]]\n\n  > [[ julgado 1 ]]\n\nConforme [[JULGADO 3]], x.\n\n[[JULGADO 2]]"),
        );
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
