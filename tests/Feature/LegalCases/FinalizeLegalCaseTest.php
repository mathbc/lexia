<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Domain\Accounts\Models\Account;
use App\Domain\CourtDecisions\Models\CourtDecision;
use App\Domain\LegalCases\Actions\DraftLegalPleading;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalPleadings\Models\LegalPleading;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Concluding a pleading: the first gesture in this project that finishes one.
 *
 * `is_draft` had a column, an index, a filter, a badge and a factory state
 * before anything could write `false` to it. What is pinned here is the three
 * effects of that gesture and, above all, **the boundary between them**: the
 * two lists and the flag are one transaction, and the drafting is deliberately
 * outside it, so a provider being down cannot undo minutes of research.
 *
 * The two lists, since step 7 arrived, are the theses of the forensic review
 * and the rulings of the jurisprudence analysis. Both are already rows by the
 * time this runs — the two research runs wrote them — so what the conclusion
 * posts is the lawyer's reading, and both saves are diffs.
 *
 * The agent is mocked throughout. It is exercised for real in
 * `tests/Agents/PleadingDraftingTest`, which costs inference; what this file is
 * about is the Action around it, and a test that reached Gemini would be red on
 * days the quota was.
 */
final class FinalizeLegalCaseTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_saves_the_review_registers_the_pleading_and_drafts_the_document(): void
    {
        [, $owner, $case] = $this->pleading();

        $this->fakeDrafting()
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(static fn (LegalCase $legalCase): LegalPleading => LegalPleading::factory()
                ->forLegalCase($legalCase)
                ->withContent('EXCELENTÍSSIMO SENHOR DOUTOR JUIZ DE DIREITO [...]')
                ->create());

        $this->finalize($owner, $case)->assertRedirect(route('legal-cases.pleading', $case));

        $this->assertSame('Da Prescrição Intercorrente', $case->theses()->sole()->name);
        $this->assertFalse($case->refresh()->is_draft);
        // A marca d'água chega na última etapa, e não na sexta: concluir é o
        // gesto da etapa 7, e é a gravação da jurisprudência que a move.
        $this->assertSame(LegalCaseStep::CourtDecisions, $case->refresh()->current_step);

        $pleading = $case->pleadings()->sole();
        $this->assertSame(1, $pleading->version);
        $this->assertStringContainsString('EXCELENTÍSSIMO', $pleading->content);
    }

    /**
     * The case the whole arrangement exists for.
     *
     * A quota, a timeout or a dropped connection takes the document away and
     * nothing else: the theses the research spent minutes finding are saved, the
     * pleading is registered, and the Minuta tab is what offers to try again.
     * Losing the review because a provider was down would be the wrong trade,
     * and it is the trade a single transaction would have made.
     */
    #[Test]
    public function a_failing_agent_does_not_undo_the_conclusion(): void
    {
        [, $owner, $case] = $this->pleading();

        $this->fakeDrafting()
            ->shouldReceive('handle')
            ->once()
            ->andThrow(new RuntimeException('O provedor recusou a requisição.'));

        $this->finalize($owner, $case)->assertRedirect(route('legal-cases.pleading', $case));

        $this->assertSame(1, $case->theses()->count());
        $this->assertFalse($case->refresh()->is_draft);
        $this->assertSame(0, $case->pleadings()->count());
    }

    #[Test]
    public function a_pleading_of_another_account_is_refused(): void
    {
        [, $owner] = $this->accountWithOwner();

        [$otherAccount] = $this->accountWithOwner();
        $theirs = LegalCase::factory()->forAccount($otherAccount)->create();

        // Route-model binding resolve antes do middleware de tenant: quem barra
        // é a Policy da peça, com 403 e não 404.
        $this->finalize($owner, $theirs)->assertForbidden();

        $this->assertTrue($theirs->refresh()->is_draft);
    }

    #[Test]
    public function it_validates_the_review_exactly_as_the_step_that_saves_it_does(): void
    {
        [, $owner, $case] = $this->pleading();

        $this->actingAs($owner)
            ->post(route('legal-cases.finalize', $case), ['theses' => []])
            ->assertSessionHasErrors('precedents');

        $this->assertTrue($case->refresh()->is_draft);
    }

    /**
     * Concluding with nothing to argue is a real answer.
     *
     * A lawyer who unticked every thesis the research found still finished the
     * pleading; the document simply argues from the class and the requests.
     */
    #[Test]
    public function an_empty_review_still_registers_the_pleading(): void
    {
        [, $owner, $case] = $this->pleading();

        $this->fakeDrafting()->shouldReceive('handle')->once()->andReturn($this->draft($case));

        $this->actingAs($owner)->post(
            route('legal-cases.finalize', $case),
            ['theses' => [], 'precedents' => [], 'court_decisions' => []],
        );

        $this->assertSame(0, $case->theses()->count());
        $this->assertFalse($case->refresh()->is_draft);
    }

    /**
     * A etapa 7 no gesto que a fecha: o que foi desmarcado sai da peça.
     *
     * Os julgados já são linhas quando esta tela abre — a pesquisa os gravou —,
     * então desmarcar não é "não gravar", é **apagar**, e quem apaga é o diff de
     * `SaveLegalCaseCourtDecisions`. É a mesma mecânica das teses, e o motivo de
     * a decisão do advogado poder viver no navegador até aqui.
     */
    #[Test]
    public function a_ruling_the_lawyer_unticked_is_removed_when_the_pleading_closes(): void
    {
        [, $owner, $case] = $this->pleading();

        $kept = CourtDecision::factory()->forLegalCase($case)->nth(1)->create();
        $unticked = CourtDecision::factory()->forLegalCase($case)->nth(2)->create();

        $this->fakeDrafting()->shouldReceive('handle')->once()->andReturn($this->draft($case));

        $this->finalize($owner, $case, [$this->postedRuling($kept)])
            ->assertRedirect(route('legal-cases.pleading', $case));

        $this->assertSame([$kept->id], $case->courtDecisions()->pluck('id')->all());
        $this->assertSoftDeleted($unticked);
        $this->assertFalse($case->refresh()->is_draft);
    }

    /**
     * Uma linha gravada, projetada exatamente como `LegalCaseFormProps` a manda
     * para a tela — que é a forma em que ela volta.
     *
     * @return array<string, mixed>
     */
    private function postedRuling(CourtDecision $decision): array
    {
        return [
            'id' => $decision->id,
            'title' => $decision->title,
            'locality' => $decision->locality,
            'authority' => $decision->authority,
            'summary' => $decision->summary,
            'subject' => $decision->subject,
            'source_url' => $decision->source_url,
            'urn' => $decision->urn,
            'decided_at' => $decision->decided_at?->toDateString(),
        ];
    }

    /**
     * A conclusion that runs twice does not produce two pleadings.
     *
     * Reaching the sixth step again and concluding again is a correction, and
     * the diff of the review is what handles it — the flag is already false and
     * says so a second time without complaint.
     */
    #[Test]
    public function concluding_again_corrects_the_review_instead_of_duplicating_it(): void
    {
        [, $owner, $case] = $this->pleading();

        // Duas versões, porque `(legal_case_id, version)` é único: o dublê
        // devolve o que o agente de verdade devolveria na segunda passada.
        $this->fakeDrafting()
            ->shouldReceive('handle')
            ->twice()
            ->andReturn($this->draft($case, 1), $this->draft($case, 2));

        $this->finalize($owner, $case);
        $this->finalize($owner, $case);

        $this->assertSame(1, $case->theses()->count());
        $this->assertFalse($case->refresh()->is_draft);
    }

    /**
     * O dublê da redação.
     *
     * Mock parcial de uma instância, e não `DraftLegalPleading::mock()`: as
     * Actions deste projeto são `final`, e o Mockery não consegue substituir os
     * métodos de uma classe final a não ser partindo de um objeto já construído.
     * A chave do container é a que o trait `AsFake` consulta — mesmo arranjo de
     * `ClassifyLegalCaseEndpointTest`.
     */
    private function fakeDrafting(): MockInterface
    {
        $fake = Mockery::mock(app(DraftLegalPleading::class));

        app()->instance('LaravelActions:AsFake:'.DraftLegalPleading::class, $fake);

        return $fake;
    }

    private function draft(LegalCase $case, int $version = 1): LegalPleading
    {
        return LegalPleading::factory()->forLegalCase($case)->version($version)->create();
    }

    private const string UUID_A = '11111111-1111-4111-8111-111111111111';

    /**
     * @return array{0: Account, 1: User, 2: LegalCase}
     */
    private function pleading(): array
    {
        [$account, $owner] = $this->accountWithOwner();
        $case = LegalCase::factory()->forAccount($account)->create();

        return [$account, $owner, $case];
    }

    /**
     * One thesis with the ruling that sustains it and one court decision,
     * posted the way the wizard's last step posts them: the precedent names its
     * thesis by the id the browser minted, and the decision names the row it
     * already is.
     *
     * `$decisions` is empty by default, which is what a pleading whose
     * jurisprudence run confirmed nothing posts — and what every test here that
     * is not about step 7 wants. The one that is about it passes rows.
     *
     * @param  list<array<string, mixed>>  $decisions
     * @return TestResponse<Response>
     */
    private function finalize(User $owner, LegalCase $case, array $decisions = []): TestResponse
    {

        return $this->actingAs($owner)->post(route('legal-cases.finalize', $case), [
            'theses' => [[
                'id' => self::UUID_A,
                'name' => 'Da Prescrição Intercorrente',
                'type' => 'principal_merits',
                'description' => 'Transcorrido o prazo sem constrição, opera-se a prescrição.',
                'impact' => 'Extingue a execução.',
                'legal_bases' => [
                    ['type' => 'article', 'reference' => 'Art. 40 da LEF', 'source' => 'LEF'],
                ],
            ]],
            'precedents' => [[
                'id' => null,
                'legal_thesis_id' => self::UUID_A,
                'name' => 'STJ — Tema 566 (REsp 1.340.553/RS)',
                'type' => 'repetitive_appeal',
                'description' => 'Fixa o termo inicial da prescrição intercorrente.',
                'citation' => 'BRASIL. Superior Tribunal de Justiça. REsp 1.340.553/RS.',
                'grounding' => 'Fundamenta a contagem do prazo.',
                'adherence' => '90',
            ]],
            // A etapa 7 viaja junto: os julgados já são linhas, e o que a
            // conclusão posta é a leitura do advogado — ver `$decisions`.
            'court_decisions' => $decisions,
        ]);
    }
}
