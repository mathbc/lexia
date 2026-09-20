<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Domain\Accounts\Models\Account;
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
 * review and the flag are one transaction, and the drafting is deliberately
 * outside it, so a provider being down cannot undo minutes of research.
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
        $this->assertSame(LegalCaseStep::Review, $case->refresh()->current_step);

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
            ['theses' => [], 'precedents' => []],
        );

        $this->assertSame(0, $case->theses()->count());
        $this->assertFalse($case->refresh()->is_draft);
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
     * One thesis and the ruling that sustains it, posted the way the sixth step
     * posts them: the precedent names its thesis by the id the browser minted.
     *
     * @return TestResponse<Response>
     */
    private function finalize(User $owner, LegalCase $case): TestResponse
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
        ]);
    }
}
