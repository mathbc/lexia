<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Domain\Accounts\Models\Account;
use App\Domain\CourtDecisions\Data\CourtDecisionData;
use App\Domain\LegalCases\Actions\ResearchLegalCaseCourtDecisions;
use App\Domain\LegalCases\Data\CourtDecisionResearchData;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The research that fills step 7 — "Análise de Jurisprudência".
 *
 * The twin of `ResearchLegalCaseForensicReviewTest`, and it pins the same two
 * things one step later: the result **is persisted**, and it is asked for
 * **once**. What differs is what a run produces — a flat list of documents
 * rather than theses with precedents hanging off them — and where the guard
 * lives, which is `CourtDecisionResearchData` on the way out and
 * `CourtDecisionListData` on the way in.
 *
 * The agents are mocked throughout. They are exercised for real in
 * `tests/Agents/CourtDecisionResearchTest`, which spends Gemini quota and opens
 * the LexML; what this file is about is the Action around them, and a test that
 * reached the network would be red on days the portal was.
 */
final class ResearchLegalCaseJurisprudenceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_writes_the_rulings_and_the_account_of_the_run(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $case = $this->pleading($account);

        $this->fakeResearch()->shouldReceive('handle')->once()->andReturn($this->found());

        $this->actingAs($owner)
            ->post(route('legal-cases.jurisprudence.research', $case))
            ->assertRedirect(route('legal-cases.edit', [
                'legalCase' => $case,
                'etapa' => LegalCaseStep::CourtDecisions->value,
            ]));

        $case->refresh();

        $this->assertCount(1, $case->courtDecisions);

        $decision = $case->courtDecisions[0];

        $this->assertSame('REsp 143513 / SP', $decision->title);
        $this->assertSame('Superior Tribunal de Justiça. 6ª Turma', $decision->authority);
        $this->assertStringContainsString('EMBARGOS À EXECUÇÃO', $decision->summary);
        $this->assertSame('1998-04-28', $decision->decided_at?->toDateString());
        $this->assertSame($case->account_id, $decision->account_id);

        // E o relato da rodada, que não tem tabela: sem ele a tela não
        // distingue "os tribunais não decidiram isso" de "a guarda recusou".
        $findings = $case->court_decision_findings;

        $this->assertIsArray($findings);
        $this->assertSame(
            'Os bens públicos penhorados comportam embargos à execução?',
            $findings['legal_question'],
        );
        $this->assertSame(['Confirmar se o acórdão segue vigente.'], $findings['pending']);
        $this->assertNotEmpty($findings['researched_at']);

        // A gravação também move a marca d'água, porque é a Action irmã que
        // grava — a mesma que o "Concluir" chama.
        $this->assertSame(LegalCaseStep::CourtDecisions, $case->current_step);
    }

    /**
     * O caso que motivou a coluna existir.
     *
     * Uma rodada que abriu o LexML e nada confirmou é resposta legítima e cara,
     * e grava zero julgados. Se o marcador fosse a lista, esta peça pesquisaria
     * de novo a cada visita à etapa — gastando cota toda vez.
     */
    #[Test]
    public function a_run_that_confirmed_nothing_still_records_that_it_ran(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $case = $this->pleading($account);

        $this->fakeResearch()->shouldReceive('handle')->once()->andReturn(new CourtDecisionResearchData(
            legalQuestion: 'Há julgado sobre a questão?',
            decisions: [],
            pending: ['Nada se confirmou no registro do LexML.'],
        ));

        $this->actingAs($owner)
            ->post(route('legal-cases.jurisprudence.research', $case))
            ->assertRedirect();

        $case->refresh();

        $this->assertCount(0, $case->courtDecisions);
        $this->assertIsArray($case->court_decision_findings);
        $this->assertSame(
            ['Nada se confirmou no registro do LexML.'],
            $case->court_decision_findings['pending'],
        );
    }

    /**
     * Repesquisar é destrutivo, e é por isso que é um botão.
     *
     * `SaveLegalCaseCourtDecisions` reconcilia por diff: o que o payload não
     * traz é apagado. Uma segunda rodada substitui a primeira, o que é certo
     * quando o advogado pede e seria um desastre se a tela disparasse sozinha.
     */
    #[Test]
    public function researching_again_replaces_what_the_previous_run_found(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $case = $this->pleading($account);

        $this->fakeResearch()
            ->shouldReceive('handle')
            ->twice()
            ->andReturn($this->found(), $this->found('ACO 88 embargos à execução / RJ'));

        $this->actingAs($owner)->post(route('legal-cases.jurisprudence.research', $case));
        $first = $case->refresh()->courtDecisions[0]->id;

        $this->actingAs($owner)->post(route('legal-cases.jurisprudence.research', $case));
        $case->refresh();

        $this->assertCount(1, $case->courtDecisions);
        $this->assertSame('ACO 88 embargos à execução / RJ', $case->courtDecisions[0]->title);
        $this->assertNotSame($first, $case->courtDecisions[0]->id);
    }

    /**
     * A guarda do portal roda outra vez na gravação, e não é redundância.
     *
     * `CourtDecisionResearchData` já recusa quem não aponta para um registro —
     * mas a lista também chega do navegador no "Concluir", e a coluna
     * `source_url` é a única razão pela qual uma ementa desta tabela pode ser
     * conferida por alguém. Uma linha sem registro simplesmente não é escrita.
     */
    #[Test]
    public function a_ruling_without_a_lexml_record_is_never_written(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $case = $this->pleading($account);

        $this->fakeResearch()->shouldReceive('handle')->once()->andReturn(new CourtDecisionResearchData(
            legalQuestion: 'Os bens públicos penhorados comportam embargos à execução?',
            decisions: [
                $this->decision(),
                // A capa do portal: passa pelo domínio e não confirma nada.
                $this->decision(title: 'Julgado sem registro', sourceUrl: 'https://www.lexml.gov.br/'),
                // E o que nem ementa tem: um título que ninguém pode usar.
                $this->decision(title: 'Só o nome', summary: ''),
            ],
        ));

        $this->actingAs($owner)->post(route('legal-cases.jurisprudence.research', $case));

        $this->assertCount(1, $case->refresh()->courtDecisions);
        $this->assertSame('REsp 143513 / SP', $case->courtDecisions[0]->title);
    }

    /**
     * A inferência fica fora da transação, e a falha não deixa rastro.
     *
     * Nada gravado significa `court_decision_findings` nulo, e o nulo é o que
     * faz a tela oferecer o botão em vez de mostrar uma etapa que parece
     * pesquisada.
     */
    #[Test]
    public function a_failed_run_writes_nothing_and_leaves_no_marker(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $case = $this->pleading($account);

        $this->fakeResearch()
            ->shouldReceive('handle')
            ->andThrow(new RuntimeException('504 Gateway Timeout'));

        $this->actingAs($owner)
            ->post(route('legal-cases.jurisprudence.research', $case))
            ->assertStatus(500);

        $case->refresh();

        $this->assertCount(0, $case->courtDecisions);
        $this->assertNull($case->court_decision_findings);
    }

    /**
     * Sem fatos o agente pesquisaria no escuro, e a recusa é antes da rede.
     */
    #[Test]
    public function a_pleading_without_facts_is_refused_before_any_inference(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $case = $this->pleading($account, facts: null);

        $this->fakeResearch()->shouldNotReceive('handle');

        $this->actingAs($owner)
            ->post(route('legal-cases.jurisprudence.research', $case))
            ->assertStatus(500);

        $this->assertNull($case->refresh()->court_decision_findings);
    }

    #[Test]
    public function a_pleading_of_another_account_is_refused(): void
    {
        [, $owner] = $this->accountWithOwner();
        [$other] = $this->accountWithOwner();

        $case = LegalCase::factory()->forAccount($other)->create(['facts' => 'O vizinho derrubou o muro.']);

        $this->fakeResearch()->shouldNotReceive('handle');

        $this->actingAs($owner)
            ->post(route('legal-cases.jurisprudence.research', $case))
            ->assertForbidden();
    }

    #[Test]
    public function a_guest_is_sent_to_the_login(): void
    {
        [$account] = $this->accountWithOwner();
        $case = $this->pleading($account);

        $this->post(route('legal-cases.jurisprudence.research', $case))
            ->assertRedirect('/login');
    }

    private function pleading(
        Account $account,
        ?string $facts = 'A penhora recaiu sobre bem público.',
    ): LegalCase {
        return LegalCase::factory()->forAccount($account)->create(['facts' => $facts]);
    }

    /**
     * Dublê para uma Action `final` — mesmo arranjo de
     * `ResearchLegalCaseForensicReviewTest` e `FinalizeLegalCaseTest`.
     */
    private function fakeResearch(): MockInterface
    {
        $fake = Mockery::mock(app(ResearchLegalCaseCourtDecisions::class));

        app()->instance('LaravelActions:AsFake:'.ResearchLegalCaseCourtDecisions::class, $fake);

        return $fake;
    }

    /**
     * Uma rodada com um achado, montada como `readRecords()` a devolve: a
     * ementa já é a do registro, e não a do modelo.
     */
    private function found(string $title = 'REsp 143513 / SP'): CourtDecisionResearchData
    {
        return new CourtDecisionResearchData(
            legalQuestion: 'Os bens públicos penhorados comportam embargos à execução?',
            decisions: [$this->decision(title: $title)],
            sources: ['https://www.lexml.gov.br/urn/'.self::URN],
            pending: ['Confirmar se o acórdão segue vigente.'],
        );
    }

    private const string URN = 'urn:lex:br:superior.tribunal.justica;turma.6:acordao;resp:1998-04-28;143513-288332';

    private function decision(
        string $title = 'REsp 143513 / SP',
        string $summary = 'PROCESSUAL CIVIL. EMBARGOS À EXECUÇÃO. PENHORA. NATUREZA IMPENHORÁVEL DOS BENS PÚBLICOS.',
        ?string $sourceUrl = 'https://www.lexml.gov.br/urn/'.self::URN,
    ): CourtDecisionData {
        return new CourtDecisionData(
            id: null,
            title: $title,
            locality: 'Brasil',
            authority: 'Superior Tribunal de Justiça. 6ª Turma',
            summary: $summary,
            subject: 'CABIMENTO, EMBARGOS A EXECUÇÃO, PENHORA.',
            sourceUrl: $sourceUrl,
            urn: self::URN,
            decidedAt: CarbonImmutable::parse('1998-04-28'),
        );
    }
}
