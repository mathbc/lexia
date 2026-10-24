<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\AgentsExecutionLogs\Actions\RecordAgentFailure;
use App\Domain\LegalCases\Actions\Concerns\RunsOnInferenceQueue;
use App\Domain\LegalCases\Enums\InferenceRunStatus;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Enums\ResearchSubject;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalCases\Models\ResearchRun;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * The sixth step's research, run in the `inference` queue — the twin of
 * RunLegalCaseForensicReview one step later, and arranged the same way.
 *
 * `ResearchLegalCaseJurisprudence` is the research and does not change: two
 * agents in series and one LexML read per surviving ruling, written in one
 * transaction. `handle()` claims a `court-decisions` run and queues the job;
 * `asController()` redirects to the step at once; `asJob()` runs the research
 * under the pleading's account and settles the run.
 *
 * One thing is new rather than moved. The research throws when it fails — it
 * has a single half, so there is nothing to keep apart —, and in the request
 * that throw was a 500 that reached Inertia's error dialog and never the
 * agents' execution log. The job catches it, writes it there under
 * `jurisprudence.court_decisions`, and records the failure on the run, where
 * the step reads it and offers the button again.
 */
final class RunLegalCaseJurisprudence
{
    use AsAction;
    use RunsOnInferenceQueue;

    /**
     * Two agents of 360 s in series, plus the record reads, plus the save.
     */
    public const int TIMEOUT = 900;

    public int $jobTimeout = self::TIMEOUT;

    /**
     * @return ResearchRun|null the run queued, or null when one is already on its way
     */
    public function handle(LegalCase $legalCase): ?ResearchRun
    {
        ResearchLegalCaseJurisprudence::refuseUnresearchable($legalCase);

        $run = ResearchRun::claim($legalCase, [ResearchSubject::CourtDecisions])[0] ?? null;

        if ($run instanceof ResearchRun) {
            self::dispatch($legalCase->id, $legalCase->account_id, $run->id);
        }

        return $run;
    }

    public function asJob(string $legalCaseId, string $accountId, string $runId): void
    {
        app(TenantContext::class)->actingAs($accountId, function () use ($legalCaseId, $accountId, $runId): void {
            $run = ResearchRun::query()->find($runId);

            if ($run instanceof ResearchRun && $run->start()) {
                $this->research($legalCaseId, $accountId, $run);
            }
        });
    }

    /**
     * The worker died with the research in its hands — its timeout, or a
     * deploy.
     */
    public function jobFailed(Throwable $e, string $legalCaseId, string $accountId, string $runId): void
    {
        $run = ResearchRun::acrossAllAccounts()->find($runId);

        if ($run instanceof ResearchRun && $run->settle(InferenceRunStatus::Failed, ResearchRun::INTERRUPTED)) {
            RecordAgentFailure::run($run->subject->operation(), $e, $run->subject->stage(), $accountId, $legalCaseId);
        }
    }

    private function research(string $legalCaseId, string $accountId, ResearchRun $run): void
    {
        try {
            ResearchLegalCaseJurisprudence::run(LegalCase::query()->findOrFail($legalCaseId));
        } catch (Throwable $e) {
            report($e);
            RecordAgentFailure::run($run->subject->operation(), $e, $run->subject->stage(), $accountId, $legalCaseId);
            $run->settle(InferenceRunStatus::Failed, $run->subject->failure());

            return;
        }

        $run->settle(InferenceRunStatus::Completed);
    }

    public function authorize(ActionRequest $request): bool
    {
        // A peça, como em toda etapa do assistente: quem pode escrevê-la pode
        // pedir a pesquisa do que ela cita.
        return $request->user()->can('update', $request->route('legalCase'));
    }

    /**
     * Answers at once, with a redirect to the step — like its twin.
     */
    public function asController(LegalCase $legalCase, ActionRequest $request): RedirectResponse
    {
        $this->handle($legalCase);

        return to_route('legal-cases.edit', [
            'legalCase' => $legalCase,
            'etapa' => LegalCaseStep::CourtDecisions->value,
        ]);
    }
}
