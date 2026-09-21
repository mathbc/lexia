<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Domain\Accounts\Models\Account;
use App\Domain\LegalCases\Actions\ResearchLegalCaseTheses;
use App\Domain\LegalCases\Data\ForensicReviewData;
use App\Domain\LegalCases\Data\LegalResearchData;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalPrecedents\Data\LegalPrecedentData;
use App\Domain\LegalPrecedents\Enums\LegalPrecedentType;
use App\Domain\LegalTheses\Data\LegalBasisData;
use App\Domain\LegalTheses\Data\LegalThesisData;
use App\Domain\LegalTheses\Enums\LegalBasisType;
use App\Domain\LegalTheses\Enums\LegalThesisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The research that fills step 6, now that it has somewhere to be written.
 *
 * This used to be the fifth step of `POST /pecas/classificar`, and what is
 * pinned here is the two things that move made possible: the result **is
 * persisted**, and it is asked for **once**.
 *
 * The agent is mocked throughout. It is exercised for real in
 * `tests/Agents/LegalThesisResearchTest`, which spends Gemini quota and opens
 * the STJ; what this file is about is the Action around it, and a test that
 * reached the network would be red on days a portal was.
 */
final class ResearchLegalCaseForensicReviewTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_writes_the_theses_the_precedents_and_the_account_of_the_run(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $case = $this->pleading($account);

        $this->fakeResearch()->shouldReceive('handle')->once()->andReturn($this->found());

        $this->actingAs($owner)
            ->post(route('legal-cases.forensic-review.research', $case))
            ->assertRedirect(route('legal-cases.edit', [
                'legalCase' => $case,
                'etapa' => LegalCaseStep::Review->value,
            ]));

        $case->refresh();

        $this->assertCount(1, $case->theses);
        $this->assertSame('Ilegitimidade passiva do sócio-administrador', $case->theses[0]->name);

        // O precedente aponta para a tese pelo id que o banco cunhou, e não
        // pelo de correlação que veio do agente: é o mapa de
        // `SaveLegalCaseForensicReview` que faz essa troca.
        $this->assertCount(1, $case->precedents);
        $this->assertSame($case->theses[0]->id, $case->precedents[0]->legal_thesis_id);

        // E o relato da rodada, que não tem tabela: sem ele a tela não
        // distingue "não achou" de "a guarda recusou".
        $findings = $case->research_findings;

        $this->assertIsArray($findings);
        $this->assertSame(
            'É cabível o redirecionamento da execução fiscal por mero inadimplemento?',
            $findings['legal_question'],
        );
        $this->assertSame(['Juntada da certidão de arquivamento.'], $findings['pending']);
        $this->assertNotEmpty($findings['researched_at']);

        // A gravação também marca a marca d'água, porque é a Action irmã que
        // grava — a mesma que o "Concluir" chama.
        $this->assertSame(LegalCaseStep::Review, $case->current_step);
    }

    /**
     * O caso que motivou a coluna existir.
     *
     * Uma rodada que abriu os portais e nada confirmou é resposta legítima e
     * cara, e grava zero teses. Se o marcador fosse a lista de teses, esta peça
     * pesquisaria de novo a cada visita à etapa — gastando cota toda vez.
     */
    #[Test]
    public function a_run_that_confirmed_nothing_still_records_that_it_ran(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $case = $this->pleading($account);

        $this->fakeResearch()->shouldReceive('handle')->once()->andReturn(new LegalResearchData(
            legalQuestion: 'O relato sustenta alguma tese?',
            review: new ForensicReviewData(theses: [], precedents: []),
            pending: ['Nada se confirmou em fonte oficial.'],
        ));

        $this->actingAs($owner)
            ->post(route('legal-cases.forensic-review.research', $case))
            ->assertRedirect();

        $case->refresh();

        $this->assertCount(0, $case->theses);
        $this->assertIsArray($case->research_findings);
        $this->assertSame(
            ['Nada se confirmou em fonte oficial.'],
            $case->research_findings['pending'],
        );
    }

    /**
     * Repesquisar é destrutivo, e é por isso que é um botão.
     *
     * `SaveLegalCaseForensicReview` reconcilia por diff: o que o payload não
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
            ->andReturn($this->found(), $this->found('Da Prescrição Intercorrente'));

        $this->actingAs($owner)->post(route('legal-cases.forensic-review.research', $case));
        $first = $case->refresh()->theses[0]->id;

        $this->actingAs($owner)->post(route('legal-cases.forensic-review.research', $case));
        $case->refresh();

        $this->assertCount(1, $case->theses);
        $this->assertSame('Da Prescrição Intercorrente', $case->theses[0]->name);
        $this->assertNotSame($first, $case->theses[0]->id);
    }

    /**
     * A inferência fica fora da transação, e a falha não deixa rastro.
     *
     * Nada gravado significa `research_findings` nulo, e o nulo é o que faz a
     * tela oferecer o botão em vez de mostrar uma etapa que parece pesquisada.
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
            ->post(route('legal-cases.forensic-review.research', $case))
            ->assertStatus(500);

        $case->refresh();

        $this->assertCount(0, $case->theses);
        $this->assertNull($case->research_findings);
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
            ->post(route('legal-cases.forensic-review.research', $case))
            ->assertStatus(500);

        $this->assertNull($case->refresh()->research_findings);
    }

    #[Test]
    public function a_pleading_of_another_account_is_refused(): void
    {
        [, $owner] = $this->accountWithOwner();
        [$other] = $this->accountWithOwner();

        $case = LegalCase::factory()->forAccount($other)->create(['facts' => 'O vizinho derrubou o muro.']);

        $this->fakeResearch()->shouldNotReceive('handle');

        $this->actingAs($owner)
            ->post(route('legal-cases.forensic-review.research', $case))
            ->assertForbidden();
    }

    #[Test]
    public function a_guest_is_sent_to_the_login(): void
    {
        [$account] = $this->accountWithOwner();
        $case = $this->pleading($account);

        $this->post(route('legal-cases.forensic-review.research', $case))
            ->assertRedirect('/login');
    }

    private function pleading(
        Account $account,
        ?string $facts = 'O sócio foi incluído por mero inadimplemento.',
    ): LegalCase {
        return LegalCase::factory()->forAccount($account)->create(['facts' => $facts]);
    }

    /**
     * Dublê para uma Action `final` — mesmo arranjo de
     * `ClassifyLegalCaseEndpointTest` e `FinalizeLegalCaseTest`.
     */
    private function fakeResearch(): MockInterface
    {
        $fake = Mockery::mock(app(ResearchLegalCaseTheses::class));

        app()->instance('LaravelActions:AsFake:'.ResearchLegalCaseTheses::class, $fake);

        return $fake;
    }

    /**
     * Uma rodada com um achado, montada como `LegalResearchData::fromAgent()` a
     * devolve: o id da tese é a chave de correlação cunhada em PHP, e é ela que
     * o precedente cita — nunca a chave primária, que ainda não existe.
     */
    private function found(string $name = 'Ilegitimidade passiva do sócio-administrador'): LegalResearchData
    {
        $thesisId = '2168abf8-94ce-435b-b9b3-bab97bf30e77';

        return new LegalResearchData(
            legalQuestion: 'É cabível o redirecionamento da execução fiscal por mero inadimplemento?',
            review: new ForensicReviewData(
                theses: [new LegalThesisData(
                    id: $thesisId,
                    name: $name,
                    type: LegalThesisType::Preliminary,
                    description: 'O mero inadimplemento não autoriza a responsabilização pessoal.',
                    impact: 'Exclusão do Embargante do polo passivo.',
                    legalBases: [new LegalBasisData(
                        type: LegalBasisType::Sumula,
                        reference: 'Súmula 430 do STJ',
                        source: 'STJ',
                    )],
                )],
                precedents: [new LegalPrecedentData(
                    id: null,
                    thesisId: $thesisId,
                    name: 'STJ — Súmula nº 430',
                    type: LegalPrecedentType::Sumula,
                    description: 'O inadimplemento não gera, por si só, responsabilidade do sócio-gerente.',
                    citation: 'STJ. Primeira Seção. Súmula nº 430.',
                    grounding: 'Impede o redirecionamento pretendido.',
                    adherence: '100.00',
                )],
            ),
            sources: ['https://www.stj.jus.br/sites/portalp/Paginas/Comunicacao/Noticias.aspx'],
            pending: ['Juntada da certidão de arquivamento.'],
        );
    }
}
