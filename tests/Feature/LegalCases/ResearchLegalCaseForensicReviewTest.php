<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Domain\Accounts\Models\Account;
use App\Domain\LegalCases\Actions\ResearchLegalCaseThemes;
use App\Domain\LegalCases\Actions\ResearchLegalCaseTheses;
use App\Domain\LegalCases\Data\ForensicReviewData;
use App\Domain\LegalCases\Data\LegalResearchData;
use App\Domain\LegalCases\Data\LegalThemeResearchData;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalPrecedents\Data\LegalPrecedentData;
use App\Domain\LegalPrecedents\Enums\LegalPrecedentType;
use App\Domain\LegalThemes\Data\LegalCaseThemeData;
use App\Domain\LegalThemes\Data\LegalCaseThemeListData;
use App\Domain\LegalThemes\Models\LegalCaseTheme;
use App\Domain\LegalThemes\Models\LegalTheme;
use App\Domain\LegalTheses\Data\LegalBasisData;
use App\Domain\LegalTheses\Data\LegalThesisData;
use App\Domain\LegalTheses\Enums\LegalBasisType;
use App\Domain\LegalTheses\Enums\LegalThesisOrigin;
use App\Domain\LegalTheses\Enums\LegalThesisType;
use App\Domain\LegalTheses\Models\LegalThesis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The research that fills step 5, now that it has somewhere to be written.
 *
 * This used to be the fifth step of `POST /pecas/classificar`, and what is
 * pinned here is the two things that move made possible: the result **is
 * persisted**, and it is asked for **once**. Since the themes tab arrived there
 * is a third: the two halves — theses and themes — run side by side and are
 * **marked, retried and failed apart**.
 *
 * The inner Actions are mocked throughout. The agents are exercised for real in
 * `tests/Agents/LegalThesisResearchTest` and `LegalThemeSelectionTest`; what
 * this file is about is the Action around them, and a test that reached the
 * network would be red on days a portal was. The suite runs the
 * `Concurrency::run` in `sync`, which is what lets the mocks be seen at all.
 */
final class ResearchLegalCaseForensicReviewTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_writes_the_theses_the_precedents_and_the_account_of_the_run(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $case = $this->pleading($account);

        $theme = LegalTheme::factory()->create();

        $this->fakeResearch()->shouldReceive('handle')->once()->andReturn($this->found());
        $this->fakeThemes()->shouldReceive('handle')->once()->andReturn($this->selected($theme));

        $this->actingAs($owner)
            ->post(route('legal-cases.forensic-review.research', $case))
            ->assertSessionHasNoErrors()
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

        // A outra aba, na mesma rodada: o vínculo com o tema, a razão que o
        // agente escreveu e o marcador que é só dela.
        $link = LegalCaseTheme::query()->where('legal_case_id', $case->id)->sole();

        $this->assertSame($theme->id, $link->legal_theme_id);
        $this->assertSame('O relato discute o reajuste por faixa etária.', $link->reason);
        $this->assertSame($account->id, $link->account_id);
        $this->assertSame(12, $case->theme_findings['considered'] ?? null);
        $this->assertSame(['Definir se é válido o reajuste de plano de saúde por faixa etária.'], $case->theme_findings['questions'] ?? null);
        $this->assertNotEmpty($case->theme_findings['researched_at'] ?? null);

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
        $this->fakeThemes()->shouldReceive('handle')->once()->andReturn(new LegalThemeResearchData(
            themes: new LegalCaseThemeListData([]),
            considered: 12,
            questions: ['Definir se o relato levanta alguma questão de direito.'],
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

        // O mesmo para os temas: nenhum se aplica é resposta, e fica marcada.
        $this->assertCount(0, $case->themes);
        $this->assertIsArray($case->theme_findings);
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

        // O botão da aba de teses pede só as teses.
        $this->fakeThemes()->shouldNotReceive('handle');

        $this->actingAs($owner)->post(route('legal-cases.forensic-review.research', $case), ['tabs' => ['theses']]);
        $first = $case->refresh()->theses[0]->id;

        $this->actingAs($owner)->post(route('legal-cases.forensic-review.research', $case), ['tabs' => ['theses']]);
        $case->refresh();

        $this->assertCount(1, $case->theses);
        $this->assertSame('Da Prescrição Intercorrente', $case->theses[0]->name);
        $this->assertNotSame($first, $case->theses[0]->id);
    }

    /**
     * A exceção à substituição: a tese que o advogado escreveu à mão.
     *
     * Ela não é da pesquisa, então a pesquisa não a apaga — continua com o
     * mesmo id, o mesmo texto e a mesma origem, enquanto a da rodada anterior é
     * trocada pela nova. Sem isso, o "Cadastrar tese" que existe justamente
     * para quando os portais não acham nada seria desfeito pelo primeiro
     * "Pesquisar novamente".
     */
    #[Test]
    public function researching_again_keeps_the_theses_written_by_hand(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $case = $this->pleading($account);

        $this->fakeResearch()
            ->shouldReceive('handle')
            ->twice()
            ->andReturn($this->found(), $this->found('Da Prescrição Intercorrente'));

        $this->fakeThemes()->shouldNotReceive('handle');

        $this->actingAs($owner)->post(route('legal-cases.forensic-review.research', $case), ['tabs' => ['theses']]);

        $manual = LegalThesis::factory()->forLegalCase($case)->manual()->nth(3)->create();

        $this->actingAs($owner)->post(route('legal-cases.forensic-review.research', $case), ['tabs' => ['theses']]);
        $case->refresh();

        $this->assertEqualsCanonicalizing(
            [$manual->name, 'Da Prescrição Intercorrente'],
            $case->theses->pluck('name')->all(),
        );

        $kept = $case->theses->firstWhere('id', $manual->id);

        $this->assertInstanceOf(LegalThesis::class, $kept);
        $this->assertSame(LegalThesisOrigin::Manual, $kept->origin);
        $this->assertSame($manual->description, $kept->description);
        // `assertEquals` porque o jsonb reordena as chaves de cada objeto.
        $this->assertEquals($manual->legal_bases, $kept->legal_bases);

        // A da pesquisa nasce como tal, sem que ninguém precise dizê-lo.
        $this->assertSame(
            LegalThesisOrigin::Ai,
            $case->theses->firstWhere('name', 'Da Prescrição Intercorrente')?->origin,
        );
    }

    /**
     * A inferência fica fora da transação, e a falha não deixa rastro — nesta
     * metade, e só nela.
     *
     * Nada gravado significa `research_findings` nulo, e o nulo é o que faz a
     * tela oferecer o botão em vez de mostrar uma etapa que parece pesquisada.
     * Os temas, que voltaram, são gravados: descartá-los cobraria do advogado
     * uma inferência que deu certo.
     */
    #[Test]
    public function a_failed_half_writes_nothing_and_leaves_the_other_half_standing(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $case = $this->pleading($account);
        $theme = LegalTheme::factory()->create();

        $this->fakeResearch()
            ->shouldReceive('handle')
            ->andThrow(new RuntimeException('504 Gateway Timeout'));
        $this->fakeThemes()->shouldReceive('handle')->once()->andReturn($this->selected($theme));

        // Um erro de validação por aba, e não um 500: é o que chega ao
        // `onError` da tela e a impede de pesquisar de novo sozinha.
        $this->actingAs($owner)
            ->post(route('legal-cases.forensic-review.research', $case))
            ->assertRedirect()
            ->assertSessionHasErrors('theses')
            ->assertSessionDoesntHaveErrors('themes');

        $case->refresh();

        $this->assertCount(0, $case->theses);
        $this->assertNull($case->research_findings);

        $this->assertSame([$theme->id], $case->themes->pluck('id')->all());
        $this->assertIsArray($case->theme_findings);
    }

    #[Test]
    public function when_both_halves_fail_nothing_is_written_and_both_tabs_are_told(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $case = $this->pleading($account);

        $this->fakeResearch()->shouldReceive('handle')->andThrow(new RuntimeException('504 Gateway Timeout'));
        $this->fakeThemes()->shouldReceive('handle')->andThrow(new RuntimeException('Ollama fora do ar'));

        $this->actingAs($owner)
            ->post(route('legal-cases.forensic-review.research', $case))
            ->assertRedirect()
            ->assertSessionHasErrors(['theses', 'themes']);

        $case->refresh();

        $this->assertCount(0, $case->theses);
        $this->assertCount(0, $case->themes);
        $this->assertNull($case->research_findings);
        $this->assertNull($case->theme_findings);
    }

    /**
     * O caso que motivou o marcador por metade.
     *
     * Uma peça pesquisada antes de a aba de temas existir tem teses curadas e
     * nenhum tema. Abrir a etapa pede só os temas — e, se a pesquisa de teses
     * rodasse junto, o diff da gravação apagaria em silêncio o que o advogado
     * já tinha decidido.
     */
    #[Test]
    public function researching_only_the_themes_leaves_the_theses_and_their_marker_alone(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $case = $this->pleading($account);
        $case->update(['research_findings' => ['legal_question' => 'Já pesquisada.', 'researched_at' => '2026-09-01T10:00:00-03:00']]);
        $thesis = LegalThesis::factory()->forLegalCase($case)->create();
        $theme = LegalTheme::factory()->create();

        $this->fakeResearch()->shouldNotReceive('handle');
        $this->fakeThemes()->shouldReceive('handle')->once()->andReturn($this->selected($theme));

        $this->actingAs($owner)
            ->post(route('legal-cases.forensic-review.research', $case), ['tabs' => ['themes']])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $case->refresh();

        $this->assertSame([$thesis->id], $case->theses->pluck('id')->all());
        $this->assertSame('Já pesquisada.', $case->research_findings['legal_question'] ?? null);
        $this->assertSame([$theme->id], $case->themes->pluck('id')->all());
    }

    /**
     * Repesquisar os temas substitui os vínculos: o `sync()` da gravação é o
     * diff, e um tema que a segunda rodada não escolheu sai da peça.
     */
    #[Test]
    public function selecting_the_themes_again_replaces_the_previous_links(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $case = $this->pleading($account);
        [$first, $second] = LegalTheme::factory()->count(2)->create();

        $this->fakeThemes()
            ->shouldReceive('handle')
            ->twice()
            ->andReturn($this->selected($first), $this->selected($second));

        $this->actingAs($owner)->post(route('legal-cases.forensic-review.research', $case), ['tabs' => ['themes']]);
        $this->actingAs($owner)->post(route('legal-cases.forensic-review.research', $case), ['tabs' => ['themes']]);

        $this->assertSame([$second->id], $case->refresh()->themes->pluck('id')->all());
    }

    #[Test]
    public function an_unknown_tab_is_refused(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $case = $this->pleading($account);

        $this->fakeResearch()->shouldNotReceive('handle');
        $this->fakeThemes()->shouldNotReceive('handle');

        $this->actingAs($owner)
            ->post(route('legal-cases.forensic-review.research', $case), ['tabs' => ['precedents']])
            ->assertSessionHasErrors('tabs.0');
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
        $this->fakeThemes()->shouldNotReceive('handle');

        $this->actingAs($owner)
            ->post(route('legal-cases.forensic-review.research', $case))
            ->assertStatus(500);

        $this->assertNull($case->refresh()->research_findings);
        $this->assertNull($case->theme_findings);
    }

    #[Test]
    public function a_pleading_of_another_account_is_refused(): void
    {
        [, $owner] = $this->accountWithOwner();
        [$other] = $this->accountWithOwner();

        $case = LegalCase::factory()->forAccount($other)->create(['facts' => 'O vizinho derrubou o muro.']);

        $this->fakeResearch()->shouldNotReceive('handle');
        $this->fakeThemes()->shouldNotReceive('handle');

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

    private function fakeThemes(): MockInterface
    {
        $fake = Mockery::mock(app(ResearchLegalCaseThemes::class));

        app()->instance('LaravelActions:AsFake:'.ResearchLegalCaseThemes::class, $fake);

        return $fake;
    }

    /**
     * Uma seleção com um tema, como `ResearchLegalCaseThemes` a devolve: o id
     * já resolvido da referência do prompt, e a razão que o agente escreveu.
     */
    private function selected(LegalTheme $theme): LegalThemeResearchData
    {
        return new LegalThemeResearchData(
            themes: new LegalCaseThemeListData([
                new LegalCaseThemeData($theme->id, 'O relato discute o reajuste por faixa etária.'),
            ]),
            considered: 12,
            questions: ['Definir se é válido o reajuste de plano de saúde por faixa etária.'],
        );
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
