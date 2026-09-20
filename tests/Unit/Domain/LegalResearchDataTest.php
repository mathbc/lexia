<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\LegalCases\Data\LegalResearchData;
use App\Domain\Shared\Support\OfficialLegalSources;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The guard that decides which citations survive a research run.
 *
 * No database, no network and no inference — which is the whole point, because
 * this is the part of the thesis research that must hold on a day when the
 * model is having a bad one. `tests/Agents/LegalThesisResearchTest` spends a
 * real quota and can go red because the STJ is slow; everything in this file is
 * deterministic and belongs in the ordinary suite.
 *
 * Why the guard exists at all, since the agent already declares an allowlist:
 * on Gemini `WebSearch::allow()` is discarded before the request is built —
 * `webSearchToolOptions()` returns `[]` — so nothing upstream stops a model from
 * grounding an answer in a blog. These assertions are the only enforcement
 * there is.
 *
 * The two halves tested here are the two halves of the decision. Membership is
 * by **registrable domain**, so a subdomain passes and a lookalike does not.
 * And a refused citation is **removed and reported**, never silently dropped:
 * a lawyer looking at a thesis with no fundamentação has to be able to tell
 * "the research found nothing" from "the guard refused what it found", because
 * those are opposite situations that produce an identical empty list.
 */
final class LegalResearchDataTest extends TestCase
{
    /**
     * @return array<string, array{string|null, bool}>
     */
    public static function urls(): array
    {
        return [
            'o Planalto, que é onde a lei mora' => ['https://www.planalto.gov.br/ccivil_03/leis/l5172compilado.htm', true],
            'um subdomínio do STJ' => ['https://scon.stj.jus.br/SCON/pesquisar.jsp?b=SUMU', true],
            'outro subdomínio do STJ' => ['https://processo.stj.jus.br/repetitivos/temas_repetitivos/', true],
            'o portal do STF' => ['https://portal.stf.jus.br/jurisprudencia/', true],
            'o Diário Oficial' => ['https://www.in.gov.br/web/dou', true],
            'o domínio nu, sem subdomínio' => ['https://stj.jus.br/', true],
            'http e não https, que ainda é o tribunal' => ['http://www.stf.jus.br/portal/', true],
            'com o ponto final que um FQDN admite' => ['https://www.planalto.gov.br./ccivil_03/', true],

            'o agregador que as instruções proíbem' => ['https://www.jusbrasil.com.br/sumula-393-stj', false],
            'um blog de escritório' => ['https://blog.advocacia.com.br/sumula-393', false],

            // O sósia: termina com o domínio oficial como substring, e é por
            // isso que o teste de sufixo carrega o ponto.
            'o sósia sem o ponto separador' => ['https://naoeoplanalto.gov.br/ccivil_03/', false],
            'o sósia com o domínio oficial no caminho' => ['https://exemplo.com/www.planalto.gov.br/lei', false],
            'o domínio oficial como subdomínio de outro' => ['https://stj.jus.br.exemplo.com/sumula', false],

            // A frase de não confirmação chega no campo da url quando o modelo
            // obedece à instrução no lugar errado. Não é uma fonte.
            'a frase de não confirmação' => ['Não localizado/confirmado em fonte oficial.', false],
            'uma sigla no lugar do endereço' => ['STJ', false],
            'o nulo' => [null, false],
            'a string vazia' => ['', false],
        ];
    }

    #[Test]
    #[DataProvider('urls')]
    public function it_recognises_only_the_official_portals(?string $url, bool $official): void
    {
        $this->assertSame($official, OfficialLegalSources::covers($url));
    }

    #[Test]
    public function it_keeps_the_citations_an_official_portal_confirmed(): void
    {
        $research = LegalResearchData::fromAgent($this->answer());

        $this->assertCount(1, $research->review->theses);

        $thesis = $research->review->theses[0];

        // Das duas normas, só a do Planalto sobrevive.
        $this->assertCount(1, $thesis->legalBases);
        $this->assertSame('Art. 135, III, do CTN', $thesis->legalBases[0]->reference);

        // Dos dois julgados, só o do STJ.
        $this->assertCount(1, $research->review->precedents);
        $this->assertSame('STJ — Súmula nº 430', $research->review->precedents[0]->name);
    }

    #[Test]
    public function it_reports_what_it_refused_instead_of_dropping_it_in_silence(): void
    {
        $research = LegalResearchData::fromAgent($this->answer());

        $this->assertTrue($research->hasUnverifiedCitations());

        // Uma norma é nomeada pela referência e um julgado pelo nome, porque são
        // as strings que a tela já mostra.
        $this->assertSame(
            ['Súmula 314 do STJ', 'STJ — Tema 981'],
            $research->unverifiedCitations,
        );
    }

    #[Test]
    public function it_keeps_the_thesis_even_when_nothing_sustains_it(): void
    {
        $research = LegalResearchData::fromAgent([
            'theses' => [[
                'name' => 'Da Prescrição Intercorrente',
                'description' => 'Argumenta a prescrição.',
                'legal_bases' => [
                    ['reference' => 'Súmula 314 do STJ', 'source_url' => 'https://www.jusbrasil.com.br/x'],
                ],
                'precedents' => [],
            ]],
        ]);

        // A tese sobrevive desarmada: perder o argumento junto com a citação
        // jogaria fora a parte que o advogado ainda usa.
        $this->assertCount(1, $research->review->theses);
        $this->assertSame([], $research->review->theses[0]->legalBases);
        $this->assertSame(['Súmula 314 do STJ'], $research->unverifiedCitations);
    }

    #[Test]
    public function it_mints_a_correlation_id_per_thesis_and_hangs_the_precedents_off_it(): void
    {
        $research = LegalResearchData::fromAgent([
            'theses' => [
                [
                    'name' => 'Primeira',
                    'description' => 'A primeira.',
                    'precedents' => [
                        ['name' => 'Julgado A', 'description' => 'Ementa A', 'source_url' => 'https://stj.jus.br/a'],
                    ],
                ],
                [
                    'name' => 'Segunda',
                    'description' => 'A segunda.',
                    'precedents' => [
                        ['name' => 'Julgado B', 'description' => 'Ementa B', 'source_url' => 'https://stj.jus.br/b'],
                    ],
                ],
            ],
        ]);

        [$first, $second] = $research->review->theses;

        $this->assertNotNull($first->id);
        $this->assertNotSame($first->id, $second->id, 'Duas teses colidiriam no mapa de ids do save.');

        // Cada julgado aponta para a tese sob a qual estava aninhado, e não para
        // a outra: é o vínculo estrutural que o schema aninhado comprou.
        $byName = [];

        foreach ($research->review->precedents as $precedent) {
            $byName[$precedent->name] = $precedent->thesisId;
        }

        $this->assertSame($first->id, $byName['Julgado A']);
        $this->assertSame($second->id, $byName['Julgado B']);
    }

    #[Test]
    public function it_drops_the_precedents_of_a_thesis_that_is_itself_dropped(): void
    {
        $research = LegalResearchData::fromAgent([
            'theses' => [[
                // Sem nome não é tese, exatamente como uma linha em branco do
                // formulário não é.
                'name' => '',
                'description' => 'Argumenta alguma coisa.',
                'precedents' => [
                    ['name' => 'Órfão', 'description' => 'Ementa', 'source_url' => 'https://stj.jus.br/x'],
                ],
            ]],
        ]);

        $this->assertSame([], $research->review->theses);

        // O julgado vai junto: um precedente cuja tese não existe chegaria ao
        // save com uma chave que nada resolve.
        $this->assertSame([], $research->review->precedents);
    }

    #[Test]
    public function it_enforces_the_ceilings_the_grammar_could_not(): void
    {
        $theses = array_map(static fn (int $n): array => [
            'name' => "Tese {$n}",
            'description' => 'Argumenta.',
            'legal_bases' => array_fill(0, 12, [
                'reference' => 'Art. 1º',
                'source_url' => 'https://www.planalto.gov.br/x',
            ]),
            'precedents' => array_fill(0, 9, [
                'name' => 'Julgado',
                'description' => 'Ementa',
                'source_url' => 'https://stj.jus.br/x',
            ]),
        ], range(1, 9));

        $research = LegalResearchData::fromAgent(['theses' => $theses]);

        $this->assertCount(LegalResearchData::MAX_THESES, $research->review->theses);

        foreach ($research->review->theses as $thesis) {
            $this->assertCount(LegalResearchData::MAX_LEGAL_BASES, $thesis->legalBases);
        }

        $this->assertCount(
            LegalResearchData::MAX_THESES * LegalResearchData::MAX_PRECEDENTS,
            $research->review->precedents,
        );
    }

    #[Test]
    public function it_credits_only_official_urls_as_sources(): void
    {
        $research = LegalResearchData::fromAgent(
            [
                'theses' => [],
                'sources_consulted' => ['https://www.planalto.gov.br/a', 'https://www.jusbrasil.com.br/b'],
                'pending' => ['Falta o comprovante de baixa.', '  ', null],
            ],
            // O que o provider reportou ter aberto. No Gemini isso costuma vir
            // como url de redirect do Vertex, que cai fora por não ser portal.
            ['https://scon.stj.jus.br/c', 'https://vertexaisearch.cloud.google.com/grounding-api-redirect/xyz'],
        );

        $this->assertSame(
            ['https://www.planalto.gov.br/a', 'https://scon.stj.jus.br/c'],
            $research->sources,
        );

        // As linhas em branco do `pending` somem; o que foi escrito fica.
        $this->assertSame(['Falta o comprovante de baixa.'], $research->pending);
    }

    #[Test]
    public function it_survives_an_answer_that_is_not_shaped_like_one(): void
    {
        $research = LegalResearchData::fromAgent([
            'legal_question' => '   ',
            'theses' => 'não é uma lista',
            'sources_consulted' => null,
            'pending' => 42,
        ]);

        $this->assertNull($research->legalQuestion);
        $this->assertSame([], $research->review->theses);
        $this->assertSame([], $research->review->precedents);
        $this->assertSame([], $research->sources);
        $this->assertSame([], $research->pending);
        $this->assertFalse($research->hasUnverifiedCitations());
    }

    /**
     * One thesis with two of everything, one confirmable and one not.
     *
     * @return array<string, mixed>
     */
    private function answer(): array
    {
        return [
            'legal_question' => 'Cabe o redirecionamento ao sócio pelo mero inadimplemento?',
            'theses' => [[
                'name' => 'Da Ilegitimidade Passiva do Sócio-Administrador',
                'type' => 'preliminary',
                'description' => 'O mero inadimplemento não autoriza o redirecionamento.',
                'impact' => 'A exclusão do sócio do polo passivo.',
                'legal_bases' => [
                    [
                        'type' => 'article',
                        'reference' => 'Art. 135, III, do CTN',
                        'source' => 'CTN',
                        'source_url' => 'https://www.planalto.gov.br/ccivil_03/leis/l5172compilado.htm',
                    ],
                    [
                        'type' => 'sumula',
                        'reference' => 'Súmula 314 do STJ',
                        'source' => 'STJ',
                        'source_url' => 'https://www.jusbrasil.com.br/sumula-314-stj',
                    ],
                ],
                'precedents' => [
                    [
                        'name' => 'STJ — Súmula nº 430',
                        'type' => 'sumula',
                        'description' => 'O inadimplemento da obrigação tributária pela sociedade não gera, por si só, a responsabilidade solidária do sócio-gerente.',
                        'citation' => 'BRASIL. Superior Tribunal de Justiça. Súmula nº 430.',
                        'grounding' => 'Afasta o fundamento invocado pela exequente.',
                        'adherence' => '95',
                        'source_url' => 'https://scon.stj.jus.br/SCON/sumula/430',
                    ],
                    [
                        'name' => 'STJ — Tema 981',
                        'type' => 'repetitive_appeal',
                        'description' => 'Ementa lida num resumo de terceiro.',
                        'citation' => null,
                        'grounding' => null,
                        'adherence' => null,
                        'source_url' => 'Não localizado/confirmado em fonte oficial.',
                    ],
                ],
            ]],
        ];
    }
}
