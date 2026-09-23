<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\CourtDecisions\Data\CourtDecisionData;
use App\Domain\CourtDecisions\Support\CourtDecisionSources;
use App\Domain\LegalCases\Data\CourtDecisionResearchData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The jurisprudence guard, without a network and without a model.
 *
 * The deterministic twin of `tests/Agents/CourtDecisionResearchTest`, and the
 * file to trust when that one is red because the LexML is down. Everything
 * pinned here is PHP: the portal check, the `/urn/` requirement, the ceiling,
 * the reporting of what was refused, and the behaviour on an answer that is not
 * shaped like one.
 *
 * What is deliberately *not* here is the minimum of three. It is a request made
 * of the agent in the prompt and held to in the agent test; this class would
 * have to invent a ruling to guarantee it, which is the one thing the whole
 * design refuses.
 *
 * Extends PHPUnit's TestCase directly, not the application's: no container, no
 * database, no tenant. Nothing in CourtDecisionResearchData touches any of them.
 */
final class CourtDecisionResearchDataTest extends TestCase
{
    /**
     * @return array<string, array{0: ?string, 1: bool}>
     */
    public static function urls(): array
    {
        return [
            'o registro de um acórdão, que é o que se cita' => [
                'https://www.lexml.gov.br/urn/urn:lex:br:superior.tribunal.justica;turma.6:acordao;resp:1998-04-28;143513-288332',
                true,
            ],
            'o mesmo registro sem o www' => [
                'https://lexml.gov.br/urn/urn:lex:br:supremo.tribunal.federal;plenario:acordao;aco:2010-03-24;342-3688759',
                true,
            ],
            'a home do portal, que não contém julgado nenhum' => ['https://www.lexml.gov.br/', false],
            'a tela de busca, bloqueada no robots.txt e atrás do desafio do Senado' => [
                'https://www.lexml.gov.br/busca/search?keyword=embargos',
                false,
            ],
            'o endpoint SRU, que vive sob /busca/ e responde com proof-of-work' => [
                'https://www.lexml.gov.br/busca/SRU?operation=searchRetrieve',
                false,
            ],
            'o STJ, que é fonte oficial para a etapa 6 e não é o catálogo desta' => [
                'https://scon.stj.jus.br/SCON/urn/urn:lex:br:x',
                false,
            ],
            'o sósia sem o ponto separador' => ['https://naoelexml.gov.br/urn/urn:lex:br:x', false],
            'um agregador comercial' => ['https://www.jusbrasil.com.br/urn/algo', false],
            'a sentinela escrita no campo do endereço' => ['Não localizado/confirmado em fonte oficial.', false],
            'o nome do portal em vez do endereço' => ['LexML', false],
            'o campo em branco' => ['   ', false],
            'o campo ausente' => [null, false],
        ];
    }

    #[Test]
    #[DataProvider('urls')]
    public function it_recognises_only_a_lexml_document_record(?string $url, bool $isRecord): void
    {
        $this->assertSame($isRecord, CourtDecisionSources::isRecord($url));
    }

    /**
     * O domínio e o registro são perguntas diferentes, e a segunda é a que vale.
     *
     * A home passa no teste de domínio e reprova no de registro, que é
     * exatamente a distinção que a guarda precisa: `covers()` diria que o
     * endereço é do portal certo, e ainda assim não há ementa nenhuma ali.
     */
    #[Test]
    public function it_separates_being_the_portal_from_being_a_record(): void
    {
        $this->assertTrue(CourtDecisionSources::covers('https://www.lexml.gov.br/'));
        $this->assertFalse(CourtDecisionSources::isRecord('https://www.lexml.gov.br/'));
    }

    #[Test]
    public function it_keeps_the_candidates_a_lexml_record_address_vouches_for(): void
    {
        $research = CourtDecisionResearchData::fromAgent($this->answer());

        $this->assertCount(1, $research->decisions);
        $this->assertSame('REsp 143513 / SP', $research->decisions[0]->title);
    }

    /**
     * O texto do julgado vem da página, e não de quem a indicou.
     *
     * A troca inteira: o que `fromAgent()` devolve são candidatos — um endereço
     * pelo qual o modelo respondeu — e é `readRecords()` que os substitui pelo
     * que o Senado publicou naquele endereço. Título, autoridade, localidade,
     * data e **ementa** saem da página, e é por isso que o registro falso do
     * `answer()` não contamina nada: ele nem chega aqui.
     */
    #[Test]
    public function it_replaces_every_candidate_with_the_record_the_portal_serves(): void
    {
        $research = CourtDecisionResearchData::fromAgent($this->answer())
            ->readRecords($this->portal());

        $this->assertCount(1, $research->decisions);

        $decision = $research->decisions[0];
        $this->assertSame('REsp 143513 / SP', $decision->title);
        $this->assertSame('Superior Tribunal de Justiça. 6ª Turma', $decision->authority);
        $this->assertSame('Brasil', $decision->locality);
        $this->assertSame('1998-04-28', $decision->decidedAt?->toDateString());
        $this->assertStringStartsWith('PROCESSUAL CIVIL. EMBARGOS À EXECUÇÃO.', $decision->summary);
    }

    /**
     * Um julgado cujo registro não abre é descartado e relatado.
     *
     * Do lado do advogado é o mesmo evento que uma citação sem procedência — o
     * agente nomeou um julgado e nada o confirmou —, então os dois caem na
     * mesma lista. Um endereço inventado, um portal fora do ar e uma página que
     * mudou de forma produzem o mesmo desfecho, porque nenhum deles torna a
     * citação segura de imprimir.
     */
    #[Test]
    public function it_drops_and_reports_a_ruling_whose_record_will_not_open(): void
    {
        $research = CourtDecisionResearchData::fromAgent([
            'decisions' => [
                $this->record(['title' => 'Julgado que abre']),
                $this->record([
                    'title' => 'Julgado que não abre',
                    'source_url' => 'https://www.lexml.gov.br/urn/urn:lex:br:inexistente',
                ]),
            ],
        ])->readRecords($this->portal());

        $this->assertCount(1, $research->decisions);
        $this->assertSame('Julgado que abre', $research->decisions[0]->title);
        $this->assertSame(['Julgado que não abre'], $research->unverifiedCitations);
    }

    /**
     * Os Assuntos são o único campo que sobrevive do candidato.
     *
     * A página do registro não imprime a linha Assuntos — ela só existe na tela
     * de busca, que o robots.txt fecha —, então os termos de indexação são a
     * leitura do agente enxertada no texto do portal. Uma página que os
     * trouxesse ganharia.
     */
    #[Test]
    public function it_carries_the_index_terms_the_record_page_does_not_print(): void
    {
        $research = CourtDecisionResearchData::fromAgent([
            'decisions' => [$this->record(['subject' => 'PENHORA, EMBARGOS A EXECUÇÃO.'])],
        ])->readRecords($this->portal());

        $this->assertSame('PENHORA, EMBARGOS A EXECUÇÃO.', $research->decisions[0]->subject);
    }

    /**
     * O mesmo acórdão duas vezes não são duas opções.
     */
    #[Test]
    public function it_keeps_one_row_per_address(): void
    {
        $research = CourtDecisionResearchData::fromAgent([
            'decisions' => [
                $this->record(['title' => 'Primeiro']),
                $this->record(['title' => 'O mesmo, de novo']),
            ],
        ]);

        $this->assertCount(1, $research->decisions);
        $this->assertSame('Primeiro', $research->decisions[0]->title);
    }

    /**
     * O que a guarda recusa é relatado, e não some.
     *
     * É a distinção que o advogado precisa fazer diante de uma lista curta:
     * "não achou nada" e "achou e a guarda recusou" são situações opostas e
     * ficam idênticas se a remoção for silenciosa.
     */
    #[Test]
    public function it_reports_what_it_refused_instead_of_dropping_it_in_silence(): void
    {
        $research = CourtDecisionResearchData::fromAgent($this->answer());

        $this->assertTrue($research->hasUnverifiedCitations());
        $this->assertSame(['AgRg no AREsp 000000 / XX'], $research->unverifiedCitations);
    }

    /**
     * Um julgado sem título ou sem ementa não é um julgado.
     *
     * Mais estrito que os irmãos, que pedem só um nome, e a condição extra se
     * paga: um acórdão é citado *pela ementa*, então um título sozinho é uma
     * referência que o advogado não consegue usar nem distinguir de uma
     * completa à primeira vista. A regra mora no leitor, que é quem tem a
     * ementa — um registro que não a imprime é lido como ilegível.
     */
    #[Test]
    public function it_drops_a_record_with_a_title_and_no_ementa(): void
    {
        $blank = CourtDecisionData::fromArray([
            'title' => 'REsp 000001 / SP',
            'summary' => '   ',
            'source_url' => 'https://www.lexml.gov.br/urn/a',
        ]);

        $this->assertFalse($blank->isWritten());

        $research = CourtDecisionResearchData::fromAgent([
            'decisions' => [$this->record(['title' => 'REsp 000001 / SP'])],
        ])->readRecords(static fn (): ?CourtDecisionData => null);

        $this->assertSame([], $research->decisions);
        $this->assertSame(['REsp 000001 / SP'], $research->unverifiedCitations);
    }

    #[Test]
    public function it_enforces_the_ceiling_the_grammar_could_not(): void
    {
        $decisions = array_map(static fn (int $n): array => [
            'title' => "Julgado {$n}",
            'source_url' => "https://www.lexml.gov.br/urn/urn:lex:br:x;acordao:2020-01-0{$n}",
        ], range(1, 9));

        $research = CourtDecisionResearchData::fromAgent(['decisions' => $decisions]);

        $this->assertCount(CourtDecisionResearchData::MAX_DECISIONS, $research->decisions);
    }

    /**
     * O corte é do teto, e não uma licença para completar.
     *
     * Duas confirmadas continuam duas: MIN_DECISIONS é um pedido feito ao
     * modelo no prompt, nunca uma promessa desta classe.
     */
    #[Test]
    public function it_never_pads_a_short_answer_up_to_the_minimum(): void
    {
        $research = CourtDecisionResearchData::fromAgent([
            'decisions' => [
                ['title' => 'REsp 143513 / SP', 'source_url' => 'https://www.lexml.gov.br/urn/urn:lex:br:x'],
                ['title' => 'ACO 342 / DF', 'source_url' => 'https://www.lexml.gov.br/urn/urn:lex:br:y'],
            ],
        ]);

        $this->assertCount(2, $research->decisions);
        $this->assertLessThan(CourtDecisionResearchData::MIN_DECISIONS, count($research->decisions));
    }

    /**
     * Só registro do LexML conta como fonte, venha do agente ou do provedor.
     *
     * A segunda lista é a que o Gemini reporta ter aberto, e na prática ela
     * chega como redirect da Vertex — que reprova, e é esse o resultado honesto:
     * creditar `vertexaisearch.cloud.google.com` como registro de tribunal seria
     * a alternativa.
     */
    #[Test]
    public function it_credits_only_lexml_records_as_sources(): void
    {
        $research = CourtDecisionResearchData::fromAgent(
            [
                'sources_consulted' => [
                    'https://www.lexml.gov.br/urn/urn:lex:br:x',
                    'https://www.lexml.gov.br/busca/search?keyword=x',
                    'https://www.jusbrasil.com.br/x',
                ],
            ],
            [
                'https://vertexaisearch.cloud.google.com/grounding-api-redirect/abc',
                'https://www.lexml.gov.br/urn/urn:lex:br:x',
                'https://www.lexml.gov.br/urn/urn:lex:br:z',
            ],
        );

        $this->assertSame(
            [
                'https://www.lexml.gov.br/urn/urn:lex:br:x',
                'https://www.lexml.gov.br/urn/urn:lex:br:z',
            ],
            $research->sources,
        );
    }

    /**
     * A data chega como o portal a escreve, e não como o parser preferiria.
     *
     * `28/04/1998` é o que a página imprime; lido pelo parser geral viraria 4
     * de agosto, se virasse alguma coisa. Uma data ilegível é nula, porque
     * perder o julgado inteiro por causa dela seria a troca pior.
     */
    #[Test]
    public function it_reads_the_date_in_the_order_the_portal_writes_it(): void
    {
        $dates = array_map(
            static fn (?string $written): ?string => CourtDecisionData::fromArray([
                'title' => 'Julgado',
                'summary' => 'EMENTA.',
                'decided_at' => $written,
            ])->decidedAt?->toDateString(),
            ['28/04/1998', '2010-03-24', 'sem data', null],
        );

        $this->assertSame(['1998-04-28', '2010-03-24', null, null], $dates);
    }

    #[Test]
    public function it_publishes_the_payload_the_screen_reads(): void
    {
        $payload = CourtDecisionResearchData::fromAgent($this->answer())
            ->readRecords($this->portal())
            ->toArray();

        $this->assertSame(
            ['legal_question', 'decisions', 'sources', 'pending', 'unverified_citations'],
            array_keys($payload),
        );

        $this->assertSame(
            ['id', 'title', 'locality', 'authority', 'summary', 'subject', 'source_url', 'urn', 'decided_at'],
            array_keys($payload['decisions'][0]),
        );

        // O id de um julgado novo é cunhado pelo banco, nunca pelo agente.
        $this->assertNull($payload['decisions'][0]['id']);
    }

    /**
     * O `nulo` da ficha não é um pendente.
     *
     * A seção `## PENDENTE` recebe a mesma palavra que os campos recebem
     * quando não há o que escrever, e o transcritor a copia como linha porque
     * ali ela não é campo nenhum. Visto na primeira rodada verde:
     * `pending: ["nulo"]`, que a tela mostraria ao advogado como um item em
     * aberto chamado "nulo".
     */
    #[Test]
    public function it_does_not_mistake_the_sheet_placeholder_for_a_pending_item(): void
    {
        $research = CourtDecisionResearchData::fromAgent([
            'pending' => [
                'nulo',
                'Nada.',
                'Não localizado/confirmado em fonte oficial.',
                'Não foi localizado julgado do STF sobre a mesma questão.',
            ],
        ]);

        $this->assertSame(
            ['Não foi localizado julgado do STF sobre a mesma questão.'],
            $research->pending,
        );
    }

    #[Test]
    public function it_survives_an_answer_that_is_not_shaped_like_one(): void
    {
        $research = CourtDecisionResearchData::fromAgent([
            'legal_question' => '   ',
            'decisions' => 'não é uma lista',
            'sources_consulted' => null,
            'pending' => 42,
        ]);

        $this->assertNull($research->legalQuestion);
        $this->assertSame([], $research->decisions);
        $this->assertSame([], $research->sources);
        $this->assertSame([], $research->pending);
        $this->assertFalse($research->hasUnverifiedCitations());
    }

    /**
     * Uma resposta com dois julgados: um conferível e um que não é.
     *
     * @return array<string, mixed>
     */
    private function answer(): array
    {
        return [
            'legal_question' => 'Cabimento e tempestividade dos embargos à execução antes de garantido o juízo.',
            'decisions' => [
                [
                    'title' => 'REsp 143513 / SP',
                    'locality' => 'Brasil',
                    'authority' => 'Superior Tribunal de Justiça. 6ª Turma',
                    'subject' => 'CABIMENTO, EMBARGOS A EXECUÇÃO, PENHORA.',
                    'urn' => 'urn:lex:br:superior.tribunal.justica;turma.6:acordao;resp:1998-04-28;143513-288332',
                    'source_url' => 'https://www.lexml.gov.br/urn/urn:lex:br:superior.tribunal.justica;turma.6:acordao;resp:1998-04-28;143513-288332',
                ],
                [
                    'title' => 'AgRg no AREsp 000000 / XX',
                    'locality' => 'Brasil',
                    'authority' => 'Superior Tribunal de Justiça',
                    'subject' => null,
                    'urn' => null,
                    'source_url' => 'https://www.jusbrasil.com.br/jurisprudencia/000000',
                ],
            ],
            'sources_consulted' => [
                'https://www.lexml.gov.br/urn/urn:lex:br:superior.tribunal.justica;turma.6:acordao;resp:1998-04-28;143513-288332',
            ],
            'pending' => ['Não foi localizado julgado do STF sobre a mesma questão.'],
        ];
    }

    /**
     * O portal, sem portal: um registro que abre e todo o resto que não.
     *
     * É o que `LexmlRecordReader` faz em produção, com a diferença que importa
     * para este arquivo — nada de rede. A assinatura é a mesma, porque
     * `readRecords()` recebe um callable justamente para que esta classe nunca
     * conheça o leitor de verdade.
     *
     * @return callable(string): ?CourtDecisionData
     */
    private function portal(): callable
    {
        $served = [
            'https://www.lexml.gov.br/urn/urn:lex:br:superior.tribunal.justica;turma.6:acordao;resp:1998-04-28;143513-288332' => [
                'title' => 'REsp 143513 / SP',
                'locality' => 'Brasil',
                'authority' => 'Superior Tribunal de Justiça. 6ª Turma',
                'summary' => 'PROCESSUAL CIVIL. EMBARGOS À EXECUÇÃO. PENHORA. NATUREZA IMPENHORÁVEL DOS BENS PÚBLICOS. CABIMENTO.',
                'urn' => 'urn:lex:br:superior.tribunal.justica;turma.6:acordao;resp:1998-04-28;143513-288332',
                'decided_at' => '28/04/1998',
            ],
            'https://www.lexml.gov.br/urn/a' => [
                'title' => 'Julgado que abre',
                'locality' => 'Brasil',
                'authority' => 'Superior Tribunal de Justiça. 1ª Turma',
                'summary' => 'EMENTA. O texto que o tribunal publicou.',
                'urn' => 'urn:lex:br:x',
                'decided_at' => '11/03/2024',
            ],
        ];

        return static fn (string $url): ?CourtDecisionData => isset($served[$url])
            ? CourtDecisionData::fromArray([...$served[$url], 'source_url' => $url])
            : null;
    }

    /**
     * Uma linha como o transcritor a devolve: endereço e identificação, sem
     * ementa — que é o campo que o schema dele deliberadamente não tem.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function record(array $overrides = []): array
    {
        return [
            'title' => 'Julgado',
            'source_url' => 'https://www.lexml.gov.br/urn/a',
            ...$overrides,
        ];
    }
}
