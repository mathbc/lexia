<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Domain\Accounts\Models\Account;
use App\Domain\LegalCases\Actions\RunLegalCaseForensicReview;
use App\Domain\LegalCases\Enums\InferenceRunStatus;
use App\Domain\LegalCases\Enums\ResearchSubject;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalCases\Models\ResearchRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The life of a queued inference — TracksInferenceRun, on a research run.
 *
 * What the screens rely on is pinned here: the effective state turns an
 * abandoned row into a failure, and every transition is conditional, so the
 * outcome is written once and never over. The flows around it — the routes,
 * the jobs, the props — are pinned in the tests of each Action.
 */
final class InferenceRunLifecycleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_queued_run_is_believed_until_its_pickup_time(): void
    {
        config(['ai.queue.pickup_timeout' => 300]);

        $run = $this->queued();

        $this->travel(299)->seconds();
        $this->assertTrue($run->isPending());
        $this->assertSame(InferenceRunStatus::Queued, $run->effectiveStatus());

        $this->travel(2)->seconds();
        $this->assertFalse($run->isPending());
        $this->assertSame(InferenceRunStatus::Failed, $run->effectiveStatus());
        $this->assertSame(ResearchRun::NOT_STARTED, $run->effectiveFailure());

        // A leitura não grava nada: o veredito só vira linha quando alguém
        // precisa dele — a próxima rodada, ou o worker que acorda tarde.
        $this->assertSame(InferenceRunStatus::Queued, $run->refresh()->status);
    }

    #[Test]
    public function a_running_run_is_believed_for_its_job_timeout_and_a_margin(): void
    {
        $run = $this->queued();
        $this->assertTrue($run->start());

        $this->travel(RunLegalCaseForensicReview::TIMEOUT + 119)->seconds();
        $this->assertSame(InferenceRunStatus::Running, $run->effectiveStatus());

        $this->travel(2)->seconds();
        $this->assertSame(InferenceRunStatus::Failed, $run->effectiveStatus());
        $this->assertSame(ResearchRun::INTERRUPTED, $run->effectiveFailure());

        $this->assertTrue($run->expire());
        $this->assertSame(InferenceRunStatus::Failed, $run->refresh()->status);
        $this->assertSame(ResearchRun::INTERRUPTED, $run->failure);
    }

    #[Test]
    public function an_outcome_is_written_once(): void
    {
        $run = $this->queued();

        // Settling needs a running row: a queued one has no outcome to give.
        $this->assertFalse($run->settle(InferenceRunStatus::Completed));
        $this->assertSame(InferenceRunStatus::Queued, $run->status);

        $this->assertTrue($run->start());
        $this->assertFalse($run->start());

        $this->assertTrue($run->settle(InferenceRunStatus::Failed, 'A primeira palavra.'));
        $this->assertFalse($run->settle(InferenceRunStatus::Completed));

        $run->refresh();

        $this->assertSame(InferenceRunStatus::Failed, $run->status);
        $this->assertSame('A primeira palavra.', $run->failure);
        $this->assertNotNull($run->finished_at);
    }

    #[Test]
    public function one_pleading_claims_never_count_against_another(): void
    {
        $account = Account::factory()->create();
        $first = LegalCase::factory()->forAccount($account)->create();
        $second = LegalCase::factory()->forAccount($account)->create();

        $this->assertCount(1, ResearchRun::claim($first, [ResearchSubject::Theses]));
        $this->assertCount(1, ResearchRun::claim($second, [ResearchSubject::Theses]));
        $this->assertCount(0, ResearchRun::claim($first, [ResearchSubject::Theses]));

        $this->assertTrue($first->hasResearchInFlight());
        $this->assertTrue($second->hasResearchInFlight());
    }

    private function queued(): ResearchRun
    {
        $legalCase = LegalCase::factory()->forAccount(Account::factory()->create())->create();

        return ResearchRun::claim($legalCase, [ResearchSubject::Theses])[0];
    }
}
