<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Accounts\Models\Account;
use App\Domain\CourtDecisions\Models\CourtDecision;
use App\Domain\LegalCases\Models\LegalCase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourtDecision>
 */
class CourtDecisionFactory extends Factory
{
    protected $model = CourtDecision::class;

    /**
     * Real LexML records, verbatim, for the reason LegalPrecedentFactory gives
     * and then one more.
     *
     * The shared reason: a seeded row that reads "ACO 342 embargos à
     * execução-AgR / DF - DISTRITO FEDERAL" shows what the column holds, and a
     * screenshot of seeded data looks like the product instead of like lorem
     * ipsum.
     *
     * The extra one, particular to this table: every field here has a shape the
     * catalogue imposes. A URN is not a uuid — it is
     * `urn:lex:br:<autoridade>;<órgão>:<tipo>;<sigla>:<data>;<número>`, and a
     * faked one would not exercise a column meant to hold it. The same goes for
     * the Autoridade with its órgão julgador after a full stop, and for an
     * ementa written in the block capitals the courts publish in. These five
     * were read off the portal, and their `source_url` opens.
     *
     * @var list<array{title: string, locality: string, authority: string, summary: string, subject: ?string, urn: string, decided_at: string}>
     */
    private const array DECISIONS = [
        [
            'title' => 'ACO 342 embargos à execução-AgR / DF - DISTRITO FEDERAL',
            'locality' => 'Brasil',
            'authority' => 'Supremo Tribunal Federal. Plenário',
            'summary' => 'COMPETÊNCIA – EMBARGOS À EXECUÇÃO. Conforme disposto no Regimento Interno do Supremo, é possível o julgamento dos embargos à execução no campo monocrático. TÍTULO EXECUTIVO JUDICIAL – LIQUIDAÇÃO – ARTIGOS VERSUS CÁLCULOS. Contando o título executivo com os parâmetros da obrigação de dar, revela-se cabível a liquidação mediante cálculos. EXECUÇÃO – OBRIGAÇÃO CONTINUADA NO TEMPO. Ao devedor cumpre a prova de fato a limitar, no tempo, a execução. OBRIGAÇÃO DE DAR – REPOSIÇÃO DO PODER AQUISITIVO. É ínsito ao integral cumprimento da obrigação de dar a atualização da moeda, circunstância tornada estreme de dúvidas ante a previsão no título executivo judicial. JUROS DA MORA – TERMOS INICIAL E FINAL. Os juros da mora incidem considerado o período de inadimplemento.',
            'subject' => null,
            'urn' => 'urn:lex:br:supremo.tribunal.federal;plenario:acordao;aco:2010-03-24;342-3688759',
            'decided_at' => '2010-03-24',
        ],
        [
            'title' => 'REsp 143513 / SP',
            'locality' => 'Brasil',
            'authority' => 'Superior Tribunal de Justiça. 6ª Turma',
            'summary' => 'PROCESSUAL CIVIL. EMBARGOS À EXECUÇÃO. PENHORA. NATUREZA IMPENHORÁVEL DOS BENS PÚBLICOS. CABIMENTO. - A lei processual civil, no título que trata dos embargos do devedor, disciplina os embargos à execução fundada em sentença, os embargos à execução fundada em título extrajudicial, os embargos à arrematação e à adjudicação e os embargos à execução por carta, não fazendo menção a qualquer espécie processual sob a denominação de embargos à penhora. - Em sede de execução de sentença, insurgindo-se o devedor contra a penhora imposta sobre seus bens, deve fazê-lo por meio do instrumento processual nominado dos embargos à execução, sendo irrelevante a circunstância de se tratarem de bens impenhoráveis de entidade pública. - Recurso especial conhecido.',
            'subject' => 'CABIMENTO, RENOVAÇÃO, EMBARGOS A EXECUÇÃO, IMPUGNAÇÃO, REALIZAÇÃO, PENHORA, DIVERSIDADE, BEM, VINCULAÇÃO, ANTERIORIDADE, EMBARGOS A EXECUÇÃO, NÃO OCORRENCIA, COISA JULGADA, INEXISTENCIA, PREVISÃO EXPRESSA, CODIGO DE PROCESSO CIVIL, EMBARGOS, PENHORA.',
            'urn' => 'urn:lex:br:superior.tribunal.justica;turma.6:acordao;resp:1998-04-28;143513-288332',
            'decided_at' => '1998-04-28',
        ],
        [
            'title' => 'ACO 408 embargos à execução-AgR / SP - SÃO PAULO',
            'locality' => 'Brasil',
            'authority' => 'Supremo Tribunal Federal. Plenário',
            'summary' => 'PRESCRIÇÃO - EXECUÇÃO. A ação de execução segue, sob o ângulo do prazo prescricional, a sorte da ação de conhecimento, como previsto no Verbete nº 150 da Súmula desta Corte, segundo o qual "prescreve a execução no mesmo prazo de prescrição da ação". PRESCRIÇÃO - RESTITUIÇÃO DE TRIBUTO - DUALIDADE. A norma do artigo 168 do Código Tributário Nacional, reveladora do prazo prescricional de cinco anos, é aplicável em se verificando o ingresso imediato no Judiciário. Tratando-se de situação concreta em que adentrada a via administrativa, não se logrando êxito, o prazo é de dois anos, tendo como termo inicial a ciência da decisão que haja implicado o indeferimento do pleito de restituição.',
            'subject' => null,
            'urn' => 'urn:lex:br:supremo.tribunal.federal;plenario:acordao;aco:2003-05-29;408-3688938',
            'decided_at' => '2003-05-29',
        ],
        [
            'title' => 'ACO 88 embargos à execução / RJ - RIO DE JANEIRO',
            'locality' => 'Brasil',
            'authority' => 'Supremo Tribunal Federal. Plenário',
            'summary' => '- EMBARGOS A EXECUÇÃO JULGADOS PROCEDENTES. AÇÃO ANULATORIA DE PARCIAL ANULAÇÃO DE DECRETO CONCESSIVO DE AFORAMENTO JULGADA IMPROCEDENTE. EXECUÇÃO LIMITADA AOS HONORARIOS E DESPESAS PROCESSUAIS, SEM QUE NELA CAIBA DIRIMIR QUESTÃO POSSESSORIA, ESTRANHA AO OBJETO DA CAUSA, A SER, PORTANTO, DISCUTIDA EM AÇÃO PROPRIA.',
            'subject' => null,
            'urn' => 'urn:lex:br:supremo.tribunal.federal;plenario:acordao;aco:1993-11-12;88-3539965',
            'decided_at' => '1993-11-12',
        ],
        [
            'title' => 'Acórdão nº 378637 do Processo nº20000710116612apc',
            'locality' => 'Distrito Federal',
            'authority' => 'Tribunal de Justiça do Distrito Federal e dos Territórios. 5ª Turma Cível',
            'summary' => 'PROCESSO CIVIL. EMBARGOS DE DECLARAÇÃO. LOSANGO PROMOTORA DE VENDAS. EXECUÇÃO DE VERBA HONORÁRIA. TEMPESTIVIDADE DOS EMBARGOS À EXECUÇÃO. OMISSÃO. SUPRIMENTO. 1. SE ANTES DA EFETIVAÇÃO DA PENHORA NÃO HOUVE QUALQUER CONSTRIÇÃO DE BENS VÁLIDA, É DE SE CONSIDERAR COMO TERMO A QUO, PARA OFERECIMENTO DOS EMBARGOS À EXECUÇÃO, A DATA DA INTIMAÇÃO DA PENHORA, REPRESENTADA PELO DEPÓSITO JUDICIAL APRESENTADO EM CARTÓRIO. 2. LAVRADO O TERMO DE PENHORA, E CERTIFICADO NOS AUTOS A INTIMAÇÃO DO DEVEDOR, É DE SE REPUTAR TEMPESTIVOS OS EMBARGOS À EXECUÇÃO AJUIZADOS EM DATA POSTERIOR À SEGURANÇA DO JUÍZO, PORÉM, EM DATA ANTERIOR À INEQUÍVOCA INTIMAÇÃO DA CONSTRIÇÃO. PRECEDENTE STJ (RESP 242.076/PR, MIN. CASTRO FILHO, TERCEIRA TURMA, DJU, 2-4-2007, P. 262) 3. RECURSO PROVIDO PARA SUPRIMENTO DA OMISSÃO, AFIRMANDO-SE TEMPESTIVOS OS EMBARGOS À EXECUÇÃO MANEJADOS, SEM ALTERAÇÃO DO MÉRITO DO ACÓRDÃO GUERREADO.',
            'subject' => 'PROCEDÊNCIA, EMBARGOS DE DECLARAÇÃO, EMBARGOS À EXECUÇÃO, NECESSIDADE, INTEGRAÇÃO, ACÓRDÃO, DECORRÊNCIA, OMISSÃO, RECONHECIMENTO, TEMPESTIVIDADE, PETIÇÃO, EMBARGOS À EXECUÇÃO, INOCORRÊNCIA, EFEITO INFRINGENTE.',
            'urn' => 'urn:lex:br;distrito.federal:tribunal.justica.distrito.federal.territorios;turma.civel.5:acordao:2009-09-23;378637',
            'decided_at' => '2009-09-23',
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $decision = fake()->randomElement(self::DECISIONS);

        return [
            'account_id' => Account::factory(),
            // O fecho recebe os atributos já sorteados para que a peça nasça na
            // mesma conta do julgado: um `LegalCase::factory()` solto a poria
            // numa segunda conta e produziria uma linha que nenhum caso de uso
            // poderia ter escrito.
            'legal_case_id' => fn (array $attributes): string => LegalCase::factory()
                ->create(['account_id' => $attributes['account_id']])
                ->id,
            ...self::columns($decision),
        ];
    }

    public function forLegalCase(LegalCase $legalCase): static
    {
        return $this->state(fn (): array => [
            'account_id' => $legalCase->account_id,
            'legal_case_id' => $legalCase->id,
        ]);
    }

    /**
     * A record the portal filed without Assuntos — the null that means the page
     * did not print one, rather than a ruling about nothing.
     */
    public function unclassified(): static
    {
        return $this->state(fn (): array => ['subject' => null]);
    }

    /**
     * For a test that needs two rows it can tell apart by title.
     */
    public function nth(int $index): static
    {
        $decision = self::DECISIONS[$index % count(self::DECISIONS)];

        return $this->state(fn (): array => self::columns($decision));
    }

    /**
     * One catalogue record as the columns hold it.
     *
     * `source_url` is composed from the URN rather than stored beside it,
     * because on this portal it *is* the URN: `/urn/` plus the name resolves to
     * the document's page. Composing it keeps the two from drifting apart in a
     * fixture, which is the one place a wrong pair would be invisible.
     *
     * @param  array{title: string, locality: string, authority: string, summary: string, subject: ?string, urn: string, decided_at: string}  $decision
     * @return array<string, mixed>
     */
    private static function columns(array $decision): array
    {
        return [
            'title' => $decision['title'],
            'locality' => $decision['locality'],
            'authority' => $decision['authority'],
            'summary' => $decision['summary'],
            'subject' => $decision['subject'],
            'source_url' => 'https://www.lexml.gov.br/urn/'.$decision['urn'],
            'urn' => $decision['urn'],
            'decided_at' => $decision['decided_at'],
        ];
    }
}
