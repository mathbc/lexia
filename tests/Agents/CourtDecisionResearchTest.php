<?php

declare(strict_types=1);

namespace Tests\Agents;

use App\Domain\CourtDecisions\Data\CourtDecisionData;
use App\Domain\CourtDecisions\Support\CourtDecisionSources;
use App\Domain\LegalCases\Actions\ResearchLegalCaseCourtDecisions;
use App\Domain\LegalCases\Data\CourtDecisionResearchData;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The jurisprudence research pair against the real provider, no fakes.
 *
 * The second agent test that leaves the machine, and it reads like its sibling
 * LegalThesisResearchTest: it spends a Gemini quota and depends on the LexML
 * being up, so a red build here is not automatically a prompt regression — it
 * can be the Senado's portal being slow. Budget minutes, not seconds: two
 * serial `Timeout(360)`, the first of which runs the searches before answering.
 *
 * **Everything this file asserts that does not depend on the model is also
 * asserted, deterministically and without a network, in
 * `tests/Unit/Domain/CourtDecisionResearchDataTest`.** That is the file to
 * trust when this one is red for reasons outside the code.
 *
 * No RefreshDatabase: the pleading is built in memory with its two relations
 * set by hand, so nothing is written and no catalogue is needed.
 * `LegalCaseDossier::forCourtDecisions()` only ever touches the model it is
 * handed.
 *
 * ## What is pinned, and what deliberately is not
 *
 * **Which** rulings answer a case is judgement, and pinning "it must find REsp
 * 143.513" would pin a legal opinion to a build. What is held firmly is the
 * contract, the guard, and the one number the product promised:
 *
 * - the shape of the payload, so a rename breaks here;
 * - that every surviving decision came from a LexML `/urn/` record — that is
 *   not a model behaviour at all, it is CourtDecisionResearchData, and it must
 *   hold for any model on any day;
 * - the ceiling, enforced in PHP;
 * - **at least three decisions**, which is what the screen already promises the
 *   lawyer and therefore the one count worth failing a build over. Note the
 *   asymmetry with the guard: three is asked of the *agent* here, and is
 *   deliberately not enforced in `CourtDecisionResearchData`, which would have
 *   to invent a ruling to guarantee it.
 *
 * What is held loosely: which rulings, from which courts, and in what order.
 *
 * ## The one thing worth watching in the dump
 *
 * Whether the research actually happened. Tidy decisions with `sources` empty
 * and ementas that read like summaries are a model answering from memory, which
 * is the failure the two-agent split exists to prevent and which no assertion
 * catches reliably — a fabricated acórdão is shaped exactly like a real one,
 * and carries a URN that looks canonical. Open one `source_url` from the dump
 * and compare the ementa to the page.
 *
 * ```bash
 * composer test:agents
 * ```
 */
#[Group('agents')]
final class CourtDecisionResearchTest extends TestCase
{
    #[Test]
    public function it_finds_the_rulings_handed_down_in_a_tax_enforcement_defence(): void
    {
        $facts = <<<'TXT'
        A Fazenda Pública do Estado ajuizou execução fiscal contra a minha empresa,
        uma transportadora, cobrando ICMS declarado e não pago entre 2017 e 2018.
        Fui citado como sócio-administrador e a Fazenda pediu o redirecionamento
        da execução contra mim, alegando apenas que o imposto não foi pago.

        A empresa foi encerrada e baixada regularmente na Junta Comercial em
        março de 2019, com distrato registrado e baixa no CNPJ. Não houve fraude,
        desvio nem dissolução irregular.

        Ainda não garanti o juízo, porque não tenho como depositar o valor, e
        quero apresentar embargos à execução fiscal discutindo tanto a
        tempestividade quanto a minha ilegitimidade para figurar no polo passivo.
        TXT;

        $legalCase = $this->pleading(
            new PracticeArea(['slug' => 'tributario', 'label' => 'Direito Tributário']),
            new ProceduralClass(['code' => 1118, 'name' => 'Embargos à Execução Fiscal']),
            ['facts' => $facts, 'court_addressing' => 'Vara de Execuções Fiscais da Comarca de Curitiba/PR'],
        );

        $research = ResearchLegalCaseCourtDecisions::run($legalCase);

        $this->show($facts, $research);

        // O contrato do payload. Um rename tem de estourar aqui.
        $payload = $research->toArray();
        $this->assertSame(
            ['legal_question', 'decisions', 'sources', 'pending', 'unverified_citations'],
            array_keys($payload),
        );

        // O mínimo que a tela promete ao advogado — "o agente de IA buscará três
        // jurisprudências". É o único número que reprova um build.
        $this->assertGreaterThanOrEqual(
            CourtDecisionResearchData::MIN_DECISIONS,
            count($research->decisions),
            'O agente devolveu menos julgados do que a etapa promete.',
        );

        // E o teto, que é de código e não de gramática.
        $this->assertLessThanOrEqual(
            CourtDecisionResearchData::MAX_DECISIONS,
            count($research->decisions),
        );

        // A guarda, que não depende de modelo nenhum: tudo o que sobreviveu veio
        // de um registro do LexML, porque o que não veio foi removido.
        $this->assertGuardHolds($research);

        foreach ($research->decisions as $decision) {
            $this->assertNotSame('', $decision->title);
            $this->assertNotSame('', $decision->summary);

            // O id de um julgado novo é cunhado pelo banco, nunca pelo agente.
            $this->assertNull($decision->id);

            // A frase de não confirmação é um sinal, nunca um valor.
            $this->assertStringNotContainsStringIgnoringCase('não localizado', $decision->title);
            $this->assertStringNotContainsStringIgnoringCase('não localizado', $decision->summary);
        }

        // Uma ementa é transcrita, não resumida: um julgado real não cabe numa
        // linha, e é o sinal mais barato de que a página foi mesmo aberta.
        foreach ($research->decisions as $decision) {
            $this->assertGreaterThan(
                120,
                mb_strlen($decision->summary),
                "A ementa de '{$decision->title}' é curta demais para ter sido transcrita.",
            );
        }

        // Variedade: três vezes o mesmo acórdão não são três opções.
        $urls = array_map(
            static fn (CourtDecisionData $decision): ?string => $decision->sourceUrl,
            $research->decisions,
        );

        $this->assertSameSize($urls, array_unique($urls), 'O agente repetiu o mesmo julgado.');
    }

    /**
     * A narrativa que não sustenta pesquisa nenhuma.
     *
     * O que se exige aqui não é uma lista vazia — o agente pode muito bem achar
     * julgado de direito do consumidor sobre vício de produto — e sim que a
     * resposta continue sendo uma resposta: sem exceção, sem julgado sem
     * registro, e com o que ficou em aberto dito em `pending` em vez de
     * preenchido no chute.
     *
     * É aqui que o mínimo de três **não** é cobrado, e a diferença é o desenho:
     * o mínimo é um pedido ao modelo, e uma rodada que abriu o portal e nada
     * confirmou é resposta legítima e cara.
     */
    #[Test]
    public function it_comes_back_empty_handed_rather_than_inventing(): void
    {
        $facts = 'Comprei um liquidificador e ele veio com um risco na jarra. Fiquei chateado.';

        $legalCase = $this->pleading(
            new PracticeArea(['slug' => 'consumidor', 'label' => 'Direito do Consumidor']),
            new ProceduralClass(['code' => 283, 'name' => 'Procedimento Comum Cível']),
            ['facts' => $facts],
        );

        $research = ResearchLegalCaseCourtDecisions::run($legalCase);

        $this->show($facts, $research);

        $this->assertGuardHolds($research);
        $this->assertLessThanOrEqual(
            CourtDecisionResearchData::MAX_DECISIONS,
            count($research->decisions),
        );
    }

    /**
     * Nenhum julgado sobrevive sem um registro do LexML por trás.
     *
     * A asserção que tem de passar com qualquer modelo em qualquer dia: quem a
     * faz passar é CourtDecisionResearchData, e não o agente. Se esta ficar
     * vermelha, o lugar de olhar é a guarda, não o prompt.
     *
     * Note que ela confere `/urn/` e não só o domínio. Um endereço de
     * `lexml.gov.br/busca/` está bloqueado no robots.txt do portal e responde
     * com a verificação de segurança do Senado — ou seja, não abre para quem
     * for conferir, que é a única coisa que uma fonte precisa fazer.
     */
    private function assertGuardHolds(CourtDecisionResearchData $research): void
    {
        foreach ($research->sources as $source) {
            $this->assertTrue(
                CourtDecisionSources::isRecord($source),
                "Uma fonte que não é registro do LexML passou pela guarda: {$source}",
            );
        }

        foreach ($research->decisions as $decision) {
            $this->assertTrue(
                CourtDecisionSources::isRecord($decision->sourceUrl),
                "Um julgado sem registro do LexML passou pela guarda: {$decision->title}",
            );
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function pleading(
        PracticeArea $area,
        ProceduralClass $class,
        array $attributes,
    ): LegalCase {
        $legalCase = new LegalCase($attributes);

        // Duas relações, e não as três da pesquisa de teses: os pedidos não
        // entram, e `forCourtDecisions()` não os lê. Defini-los seria dizer que
        // o agente os vê.
        $legalCase->setRelation('practiceArea', $area);
        $legalCase->setRelation('proceduralClass', $class);

        return $legalCase;
    }

    private function show(string $facts, CourtDecisionResearchData $research): void
    {
        // The point of this test is to look at the answer, and PHPUnit swallows
        // stdout — STDERR is what actually reaches the terminal.
        fwrite(STDERR, PHP_EOL.json_encode([
            'fatos' => $facts,
            'resposta' => $research->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL.PHP_EOL);
    }
}
