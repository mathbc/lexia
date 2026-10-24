<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Domain\Accounts\Models\Account;
use App\Domain\AgentsExecutionLogs\Models\AgentsExecutionLog;
use App\Domain\Doctrines\Data\DoctrineData;
use App\Domain\Doctrines\Models\Doctrine;
use App\Domain\LegalCases\Actions\ResearchLegalCaseDoctrines;
use App\Domain\LegalCases\Actions\ResearchLegalCaseThemes;
use App\Domain\LegalCases\Actions\ResearchLegalCaseTheses;
use App\Domain\LegalCases\Actions\RunLegalCaseForensicReview;
use App\Domain\LegalCases\Data\DoctrineResearchData;
use App\Domain\LegalCases\Data\ForensicReviewData;
use App\Domain\LegalCases\Data\LegalResearchData;
use App\Domain\LegalCases\Data\LegalThemeResearchData;
use App\Domain\LegalCases\Enums\ForensicReviewTab;
use App\Domain\LegalCases\Enums\InferenceRunStatus;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalCases\Models\ResearchRun;
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
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
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
 * is a third: the halves — theses, themes and, since the third tab, doctrine —
 * run side by side and are **marked, retried and failed apart**.
 *
 * A request without `tabs` runs every half, so every test that posts one fakes
 * all three: an unfaked doctrine half would reach the LexML.
 *
 * The inner Actions are mocked throughout. The agents are exercised for real in
 * `tests/Agents/LegalThesisResearchTest`, `LegalThemeSelectionTest` and
 * `DoctrineResearchTest`; what this file is about is the Action around them,
 * and a test that reached the network would be red on days a portal was. The
 * suite runs the `Concurrency::run` in `sync`, which is what lets the mocks be
 * seen at all.
 *
 * The route only queues now (RunLegalCaseForensicReview), and the suite runs
 * the queue in `sync` too: the job runs inside the POST, so the tests that post
 * and then read the pleading still read what the research wrote. What a failed
 * half used to say in the error bag it now says on its `research_runs` row,
 * and `assertRuns()` reads it there. The tests of the queue itself — what is
 * queued, what is never queued twice, the run nobody picked up — fake it.
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
        $this->fakeDoctrines()->shouldReceive('handle')->once()->andReturn($this->doctrines());

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

        // E a terceira aba: a obra como o registro a traz, a nota do agente ao
        // lado, e os termos da busca no marcador que é só dela.
        $doctrine = Doctrine::query()->where('legal_case_id', $case->id)->sole();

        $this->assertSame('Tupinambá, Carolina', $doctrine->author);
        $this->assertSame('Trata da responsabilidade por assalto ao empregado.', $doctrine->relevance);
        $this->assertSame($account->id, $doctrine->account_id);
        $this->assertSame(['responsabilidade civil assalto empregado'], $case->doctrine_findings['search_terms'] ?? null);
        $this->assertNotEmpty($case->doctrine_findings['researched_at'] ?? null);

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
        $this->fakeDoctrines()->shouldReceive('handle')->once()->andReturn(new DoctrineResearchData(
            searchTerms: ['questão sem doutrina'],
            doctrines: [],
            pending: ['Nenhuma obra do acervo trata da questão.'],
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

        // O mesmo para os temas e para a doutrina: nada se aplica é resposta,
        // e fica marcada.
        $this->assertCount(0, $case->themes);
        $this->assertIsArray($case->theme_findings);
        $this->assertCount(0, $case->doctrines);
        $this->assertSame(['Nenhuma obra do acervo trata da questão.'], $case->doctrine_findings['pending'] ?? null);
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
        $this->fakeDoctrines()->shouldReceive('handle')->once()->andReturn($this->doctrines());

        // A falha mora na rodada da aba, e não mais no bag de erros: é ela que
        // a tela lê — também depois de um reload — e que a impede de
        // pesquisar de novo sozinha.
        $this->actingAs($owner)
            ->post(route('legal-cases.forensic-review.research', $case))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertRuns($case, [
            'theses' => InferenceRunStatus::Failed,
            'themes' => InferenceRunStatus::Completed,
            'doctrines' => InferenceRunStatus::Completed,
        ]);
        $this->assertSame(
            ForensicReviewTab::Theses->failure(),
            ResearchRun::acrossAllAccounts()->where('subject', 'theses')->sole()->failure,
        );

        $case->refresh();

        $this->assertCount(0, $case->theses);
        $this->assertNull($case->research_findings);

        $this->assertSame([$theme->id], $case->themes->pluck('id')->all());
        $this->assertIsArray($case->theme_findings);
        $this->assertCount(1, $case->doctrines);
        $this->assertIsArray($case->doctrine_findings);

        // E o registro de execução, com a Action da aba no lugar do agente: o
        // dublê não passa por agente nenhum, então não há um a ler do stack.
        $log = AgentsExecutionLog::query()->sole();

        $this->assertSame('forensic_review.theses', $log->operation);
        $this->assertSame('ResearchLegalCaseTheses', $log->agent);
        $this->assertStringContainsString('504 Gateway Timeout', $log->error);
        $this->assertSame($account->id, $log->account_id);
        $this->assertSame($case->id, $log->legal_case_id);
        $this->assertSame('gemini:gemini-3.7-flash', $log->model);
    }

    /**
     * A falha do provedor, como o SDK a lança: a exceção que chega é a dele, e
     * o status e a frase do Gemini estão um `previous` abaixo. É essa que vai
     * para o registro — "o provedor está sobrecarregado" não diria nada.
     */
    #[Test]
    public function a_provider_error_is_logged_with_its_status_and_its_message(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $case = $this->pleading($account);

        $this->fakeResearch()->shouldReceive('handle')->andThrow(ProviderOverloadedException::forProvider(
            'gemini',
            503,
            new RequestException(new Response(new Psr7Response(
                503,
                ['Content-Type' => 'application/json'],
                '{"error":{"code":503,"message":"The model is overloaded. Please try again later.","status":"UNAVAILABLE"}}',
            ))),
        ));

        $this->actingAs($owner)
            ->post(route('legal-cases.forensic-review.research', $case), ['tabs' => ['theses']])
            ->assertSessionHasNoErrors();

        $this->assertRuns($case, ['theses' => InferenceRunStatus::Failed]);

        $log = AgentsExecutionLog::query()->sole();

        $this->assertSame(503, $log->http_status);
        $this->assertSame('The model is overloaded. Please try again later.', $log->message);
        $this->assertStringContainsString(ProviderOverloadedException::class, $log->error);
    }

    /**
     * A busca de doutrina sai da máquina como a de teses, e cai pelos mesmos
     * motivos — a cota, o LexML fora do ar. Ela cai sozinha.
     */
    #[Test]
    public function a_failed_doctrine_search_is_told_on_its_own_tab(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $case = $this->pleading($account);
        $theme = LegalTheme::factory()->create();

        $this->fakeResearch()->shouldReceive('handle')->once()->andReturn($this->found());
        $this->fakeThemes()->shouldReceive('handle')->once()->andReturn($this->selected($theme));
        $this->fakeDoctrines()->shouldReceive('handle')->andThrow(new RuntimeException('429 Too Many Requests'));

        $this->actingAs($owner)
            ->post(route('legal-cases.forensic-review.research', $case))
            ->assertRedirect();

        $this->assertRuns($case, [
            'theses' => InferenceRunStatus::Completed,
            'themes' => InferenceRunStatus::Completed,
            'doctrines' => InferenceRunStatus::Failed,
        ]);

        $case->refresh();

        $this->assertCount(0, $case->doctrines);
        $this->assertNull($case->doctrine_findings);
        $this->assertCount(1, $case->theses);
        $this->assertCount(1, $case->themes);

        $this->assertSame(['forensic_review.doctrines'], AgentsExecutionLog::query()->pluck('operation')->all());
    }

    #[Test]
    public function when_every_half_fails_nothing_is_written_and_every_tab_is_told(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $case = $this->pleading($account);

        $this->fakeResearch()->shouldReceive('handle')->andThrow(new RuntimeException('504 Gateway Timeout'));
        $this->fakeThemes()->shouldReceive('handle')->andThrow(new RuntimeException('Ollama fora do ar'));
        $this->fakeDoctrines()->shouldReceive('handle')->andThrow(new RuntimeException('504 Gateway Timeout'));

        $this->actingAs($owner)
            ->post(route('legal-cases.forensic-review.research', $case))
            ->assertRedirect();

        $this->assertRuns($case, [
            'theses' => InferenceRunStatus::Failed,
            'themes' => InferenceRunStatus::Failed,
            'doctrines' => InferenceRunStatus::Failed,
        ]);

        $this->assertEqualsCanonicalizing(
            ['forensic_review.theses', 'forensic_review.themes', 'forensic_review.doctrines'],
            AgentsExecutionLog::query()->pluck('operation')->all(),
        );

        $case->refresh();

        $this->assertCount(0, $case->theses);
        $this->assertCount(0, $case->themes);
        $this->assertCount(0, $case->doctrines);
        $this->assertNull($case->research_findings);
        $this->assertNull($case->theme_findings);
        $this->assertNull($case->doctrine_findings);
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
        $this->fakeDoctrines()->shouldNotReceive('handle');

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

    /**
     * O caso de toda peça que já existia quando a aba de doutrina chegou: teses
     * e temas curados, e o marcador da doutrina nulo. Abrir a etapa pede só ela.
     */
    #[Test]
    public function researching_only_the_doctrines_leaves_the_theses_and_the_themes_alone(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $case = $this->pleading($account);
        $case->update([
            'research_findings' => ['legal_question' => 'Já pesquisada.', 'researched_at' => '2026-09-01T10:00:00-03:00'],
            'theme_findings' => ['considered' => 20, 'researched_at' => '2026-09-01T10:00:00-03:00'],
        ]);
        $thesis = LegalThesis::factory()->forLegalCase($case)->create();

        $this->fakeResearch()->shouldNotReceive('handle');
        $this->fakeThemes()->shouldNotReceive('handle');
        $this->fakeDoctrines()->shouldReceive('handle')->once()->andReturn($this->doctrines());

        $this->actingAs($owner)
            ->post(route('legal-cases.forensic-review.research', $case), ['tabs' => ['doctrines']])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $case->refresh();

        $this->assertSame([$thesis->id], $case->theses->pluck('id')->all());
        $this->assertSame('Já pesquisada.', $case->research_findings['legal_question'] ?? null);
        $this->assertSame(20, $case->theme_findings['considered'] ?? null);
        $this->assertCount(1, $case->doctrines);
    }

    /**
     * "Pesquisar novamente" na aba de doutrina substitui as obras: a gravação é
     * um diff, e a obra que a segunda rodada não trouxe sai da peça.
     */
    #[Test]
    public function researching_the_doctrines_again_replaces_the_previous_works(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $case = $this->pleading($account);

        $this->fakeDoctrines()
            ->shouldReceive('handle')
            ->twice()
            ->andReturn(
                $this->doctrines(),
                $this->doctrines(author: 'Almeida, Carlos Alberto Neves', urn: 'urn:lex:br:rede.virtual.bibliotecas:livro:2010;000884424'),
            );

        $this->actingAs($owner)->post(route('legal-cases.forensic-review.research', $case), ['tabs' => ['doctrines']]);
        $this->actingAs($owner)->post(route('legal-cases.forensic-review.research', $case), ['tabs' => ['doctrines']]);

        $this->assertSame(['Almeida, Carlos Alberto Neves'], $case->refresh()->doctrines->pluck('author')->all());
    }

    /**
     * A guarda da gravação: uma obra sem autor não se cita, e não vira linha
     * nem quando chega pela pesquisa.
     */
    #[Test]
    public function a_work_without_an_author_is_never_written(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $case = $this->pleading($account);

        $this->fakeDoctrines()->shouldReceive('handle')->once()->andReturn($this->doctrines(author: ''));

        $this->actingAs($owner)
            ->post(route('legal-cases.forensic-review.research', $case), ['tabs' => ['doctrines']])
            ->assertSessionHasNoErrors();

        $case->refresh();

        $this->assertCount(0, $case->doctrines);
        $this->assertIsArray($case->doctrine_findings);
    }

    #[Test]
    public function an_unknown_tab_is_refused(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $case = $this->pleading($account);

        $this->fakeResearch()->shouldNotReceive('handle');
        $this->fakeThemes()->shouldNotReceive('handle');
        $this->fakeDoctrines()->shouldNotReceive('handle');

        $this->actingAs($owner)
            ->post(route('legal-cases.forensic-review.research', $case), ['tabs' => ['precedents']])
            ->assertSessionHasErrors('tabs.0');
    }

    /**
     * Sem fatos o agente pesquisaria no escuro, e a recusa é antes da rede.
     */
    /**
     * A rota só enfileira: uma rodada por aba pedida, um job na fila própria,
     * e a etapa de volta na hora. Pedir de novo uma aba que já está na fila —
     * o reload, o duplo clique, a etapa aberta em duas abas — não paga a
     * pesquisa duas vezes.
     */
    #[Test]
    public function the_route_only_queues_the_halves_asked_for_and_never_twice(): void
    {
        Queue::fake();

        [$account, $owner] = $this->accountWithOwner();
        $case = $this->pleading($account);

        $this->fakeResearch()->shouldNotReceive('handle');

        $this->actingAs($owner)
            ->post(route('legal-cases.forensic-review.research', $case), ['tabs' => ['theses', 'themes']])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('legal-cases.edit', [
                'legalCase' => $case,
                'etapa' => LegalCaseStep::Review->value,
            ]));

        $this->assertRuns($case, [
            'theses' => InferenceRunStatus::Queued,
            'themes' => InferenceRunStatus::Queued,
        ]);

        $this->actingAs($owner)
            ->post(route('legal-cases.forensic-review.research', $case), ['tabs' => ['theses', 'doctrines']]);

        // Só a doutrina é nova: as teses já estavam a caminho.
        $this->assertSame(3, ResearchRun::acrossAllAccounts()->count());
        RunLegalCaseForensicReview::assertPushedOn('inference', 2);

        $this->actingAs($owner)
            ->get(route('legal-cases.edit', ['legalCase' => $case, 'etapa' => LegalCaseStep::Review->value]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('researchRuns.theses.status', 'queued')
                ->where('researchRuns.themes.status', 'queued')
                ->where('researchRuns.doctrines.status', 'queued'));
    }

    /**
     * A rodada que nenhum worker pegou chega à tela como falha, com a frase que
     * manda olhar para a fila — e é substituída quando o advogado pede de
     * novo, em vez de bloquear a aba para sempre.
     */
    #[Test]
    public function a_run_nobody_picked_up_is_told_as_failed_and_replaced(): void
    {
        Queue::fake();
        config(['ai.queue.pickup_timeout' => 60]);

        [$account, $owner] = $this->accountWithOwner();
        $case = $this->pleading($account);

        $this->actingAs($owner)
            ->post(route('legal-cases.forensic-review.research', $case), ['tabs' => ['theses']]);

        $this->travel(61)->seconds();

        $this->actingAs($owner)
            ->get(route('legal-cases.edit', ['legalCase' => $case, 'etapa' => LegalCaseStep::Review->value]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('researchRuns.theses.status', 'failed')
                ->where('researchRuns.theses.failure', ResearchRun::NOT_STARTED));

        $this->actingAs($owner)
            ->post(route('legal-cases.forensic-review.research', $case), ['tabs' => ['theses']]);

        $runs = ResearchRun::acrossAllAccounts()->oldest()->get();

        $this->assertCount(2, $runs);
        $this->assertSame(InferenceRunStatus::Failed, $runs[0]->status);
        $this->assertSame(ResearchRun::NOT_STARTED, $runs[0]->failure);
        $this->assertSame(InferenceRunStatus::Queued, $runs[1]->status);
        RunLegalCaseForensicReview::assertPushedOn('inference', 2);

        // O job da primeira rodada, se um worker o pegar agora, não a reacende.
        $this->fakeResearch()->shouldNotReceive('handle');

        RunLegalCaseForensicReview::make()->asJob($case->id, $account->id, [$runs[0]->id]);

        $this->assertSame(InferenceRunStatus::Failed, $runs[0]->refresh()->status);
    }

    /**
     * O worker que morre com a pesquisa na mão — o teto do job, um deploy —
     * deixa a aba interrompida e uma linha no registro de execução.
     */
    #[Test]
    public function a_worker_that_dies_leaves_the_tabs_interrupted_and_logged(): void
    {
        Queue::fake();

        [$account, $owner] = $this->accountWithOwner();
        $case = $this->pleading($account);

        $this->actingAs($owner)
            ->post(route('legal-cases.forensic-review.research', $case), ['tabs' => ['theses']]);

        $run = ResearchRun::acrossAllAccounts()->sole();
        $this->assertTrue($run->start());

        RunLegalCaseForensicReview::make()->jobFailed(
            new RuntimeException('Job has timed out.'),
            $case->id,
            $account->id,
            [$run->id],
        );

        $this->assertSame(InferenceRunStatus::Failed, $run->refresh()->status);
        $this->assertSame(ResearchRun::INTERRUPTED, $run->failure);

        $log = AgentsExecutionLog::query()->sole();

        $this->assertSame('forensic_review.theses', $log->operation);
        $this->assertSame('ResearchLegalCaseTheses', $log->agent);
        $this->assertSame($case->id, $log->legal_case_id);
    }

    /**
     * Concluir com uma pesquisa a caminho gravaria a revisão que o worker
     * reescreveria em seguida. A etapa trava atrás do diálogo; esta recusa é
     * para a segunda aba do navegador.
     */
    #[Test]
    public function concluding_waits_for_a_research_in_flight(): void
    {
        Queue::fake();

        [$account, $owner] = $this->accountWithOwner();
        $case = $this->pleading($account);

        $this->actingAs($owner)
            ->post(route('legal-cases.forensic-review.research', $case), ['tabs' => ['themes']]);

        $this->actingAs($owner)
            ->post(
                route('legal-cases.finalize', $case),
                ['theses' => [], 'precedents' => [], 'themes' => [], 'doctrines' => [], 'court_decisions' => []],
            )
            ->assertRedirect(route('legal-cases.edit', [
                'legalCase' => $case,
                'etapa' => LegalCaseStep::CourtDecisions->value,
            ]))
            ->assertSessionHas('error');

        $this->assertTrue($case->refresh()->is_draft);
    }

    #[Test]
    public function a_pleading_without_facts_is_refused_before_any_inference(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $case = $this->pleading($account, facts: null);

        $this->fakeResearch()->shouldNotReceive('handle');
        $this->fakeThemes()->shouldNotReceive('handle');
        $this->fakeDoctrines()->shouldNotReceive('handle');

        $this->actingAs($owner)
            ->post(route('legal-cases.forensic-review.research', $case))
            ->assertStatus(500);

        $this->assertNull($case->refresh()->research_findings);
        $this->assertNull($case->theme_findings);
        $this->assertNull($case->doctrine_findings);
    }

    #[Test]
    public function a_pleading_of_another_account_is_refused(): void
    {
        [, $owner] = $this->accountWithOwner();
        [$other] = $this->accountWithOwner();

        $case = LegalCase::factory()->forAccount($other)->create(['facts' => 'O vizinho derrubou o muro.']);

        $this->fakeResearch()->shouldNotReceive('handle');
        $this->fakeThemes()->shouldNotReceive('handle');
        $this->fakeDoctrines()->shouldNotReceive('handle');

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

    /**
     * The state of the latest run of each subject of the pleading.
     *
     * @param  array<string, InferenceRunStatus>  $expected
     */
    private function assertRuns(LegalCase $case, array $expected): void
    {
        $runs = ResearchRun::acrossAllAccounts()
            ->where('legal_case_id', $case->id)
            ->latest()
            ->get()
            ->unique(static fn (ResearchRun $run): string => $run->subject->value)
            ->mapWithKeys(static fn (ResearchRun $run): array => [$run->subject->value => $run->status])
            ->all();

        $this->assertEquals($expected, $runs);
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

    private function fakeDoctrines(): MockInterface
    {
        $fake = Mockery::mock(app(ResearchLegalCaseDoctrines::class));

        app()->instance('LaravelActions:AsFake:'.ResearchLegalCaseDoctrines::class, $fake);

        return $fake;
    }

    /**
     * Uma rodada com uma obra, como `ResearchLegalCaseDoctrines` a devolve:
     * já lida do registro, com a nota do agente ao lado.
     */
    private function doctrines(
        string $author = 'Tupinambá, Carolina',
        string $urn = 'urn:lex:br:rede.virtual.bibliotecas:artigo.revista:2015;2001031582',
    ): DoctrineResearchData {
        return new DoctrineResearchData(
            searchTerms: ['responsabilidade civil assalto empregado'],
            doctrines: [DoctrineData::fromArray([
                'kind' => 'Artigo de revista',
                'author' => $author,
                'title' => 'A responsabilidade do empregador em decorrência de assaltos',
                'published_in' => '2015',
                'publication' => 'Revista Magister de direito do trabalho. Porto Alegre, Magister, 2011.',
                'relevance' => 'Trata da responsabilidade por assalto ao empregado.',
                'source_url' => 'https://www.lexml.gov.br/urn/'.$urn,
                'urn' => $urn,
            ])],
        );
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
