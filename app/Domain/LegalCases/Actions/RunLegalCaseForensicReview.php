<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\AgentsExecutionLogs\Actions\RecordAgentFailure;
use App\Domain\LegalCases\Actions\Concerns\RunsOnInferenceQueue;
use App\Domain\LegalCases\Enums\ForensicReviewTab;
use App\Domain\LegalCases\Enums\InferenceRunStatus;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Enums\ResearchSubject;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalCases\Models\ResearchRun;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * The fifth step's research, run in the `inference` queue.
 *
 * `ResearchLegalCaseForensicReview` is the research itself — the three halves
 * in parallel, each written as it is — and does not change. What changes is who
 * waits for it. The request used to, and behind the Cloudflare of production it
 * was cut with a 504 while the thesis research was still reading the STJ,
 * taking with it the themes and the doctrine that had already come back. Now
 * the request only queues, and a worker waits.
 *
 * `handle()` claims one `research_runs` row per half asked for — the halves
 * already on their way are skipped, so a reload or a double click does not pay
 * the research twice — and dispatches the job with their ids. `asController()`
 * answers at once with a redirect to the step, and the screen locks behind the
 * analysis dialog while the runs are pending, polling them.
 *
 * `asJob()` takes the rows (refusing the ones nobody should run any more, see
 * TracksInferenceRun), calls the research under the pleading's account and
 * settles each row: `failed` with the tab's sentence for the halves that did
 * not come back, `completed` for the rest. The failures were already written
 * to the execution log by the research's own `attempt()`, from inside the
 * process that saw them. What used to travel back as `withErrors()` under the
 * tab's key now lives on the row, and survives a reload.
 */
final class RunLegalCaseForensicReview
{
    use AsAction;
    use RunsOnInferenceQueue;

    /**
     * Above `concurrency.timeout` (840 s), so a half that hangs is killed by
     * the driver and settled as failed while the job is still alive to write
     * the others.
     */
    public const int TIMEOUT = 900;

    public int $jobTimeout = self::TIMEOUT;

    /**
     * @param  list<ForensicReviewTab>|null  $tabs  the halves to research; null is all three
     * @return list<ResearchRun> the runs queued — none when every half asked for is already on its way
     */
    public function handle(LegalCase $legalCase, ?array $tabs = null): array
    {
        ResearchLegalCaseForensicReview::refuseUnresearchable($legalCase);

        $runs = ResearchRun::claim($legalCase, array_map(
            static fn (ForensicReviewTab $tab): ResearchSubject => ResearchSubject::fromTab($tab),
            $tabs ?? ForensicReviewTab::cases(),
        ));

        if ($runs !== []) {
            self::dispatch(
                $legalCase->id,
                $legalCase->account_id,
                array_map(static fn (ResearchRun $run): string => $run->id, $runs),
            );
        }

        return $runs;
    }

    /**
     * @param  list<string>  $runIds
     */
    public function asJob(string $legalCaseId, string $accountId, array $runIds): void
    {
        app(TenantContext::class)->actingAs($accountId, function () use ($legalCaseId, $runIds): void {
            $runs = ResearchRun::query()
                ->whereKey($runIds)
                ->get()
                ->filter(static fn (ResearchRun $run): bool => $run->start())
                ->values()
                ->all();

            if ($runs !== []) {
                $this->research($legalCaseId, $runs);
            }
        });
    }

    /**
     * The worker died with the research in its hands — its timeout, or a
     * deploy. The children it spawned were not there to write the log, so this
     * does, once per half it leaves unanswered.
     *
     * @param  list<string>  $runIds
     */
    public function jobFailed(Throwable $e, string $legalCaseId, string $accountId, array $runIds): void
    {
        ResearchRun::acrossAllAccounts()
            ->whereKey($runIds)
            ->get()
            ->filter(static fn (ResearchRun $run): bool => $run->settle(InferenceRunStatus::Failed, ResearchRun::INTERRUPTED))
            ->each(static fn (ResearchRun $run): mixed => RecordAgentFailure::run(
                $run->subject->operation(),
                $e,
                $run->subject->stage(),
                $accountId,
                $legalCaseId,
            ));
    }

    /**
     * @param  list<ResearchRun>  $runs
     */
    private function research(string $legalCaseId, array $runs): void
    {
        $tabs = array_values(array_filter(array_map(
            static fn (ResearchRun $run): ?ForensicReviewTab => $run->subject->tab(),
            $runs,
        )));

        try {
            $failed = ResearchLegalCaseForensicReview::run(LegalCase::query()->findOrFail($legalCaseId), $tabs);
        } catch (Throwable $e) {
            // O que nenhuma aba segura sozinha — a peça que sumiu, os fatos
            // apagados depois do disparo, a gravação que caiu. As quedas de
            // agente já foram anotadas pelo `attempt()` de cada metade.
            report($e);
            $failed = $tabs;
        }

        foreach ($runs as $run) {
            in_array($run->subject->tab(), $failed, true)
                ? $run->settle(InferenceRunStatus::Failed, $run->subject->failure())
                : $run->settle(InferenceRunStatus::Completed);
        }
    }

    public function authorize(ActionRequest $request): bool
    {
        // A peça, como em toda etapa do assistente: quem pode escrevê-la pode
        // pedir a pesquisa do que ela argumenta.
        return $request->user()->can('update', $request->route('legalCase'));
    }

    /**
     * Absent means every half — a caller that predates the tabs gets what it
     * always got, plus the themes and the doctrine.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tabs' => ['sometimes', 'array', 'min:1'],
            'tabs.*' => ['distinct', Rule::enum(ForensicReviewTab::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return [
            'tabs' => 'abas',
            'tabs.*' => 'aba',
        ];
    }

    /**
     * Answers at once, with a redirect to the step and no errors.
     *
     * Inertia re-renders the form with the runs just queued in `researchRuns`,
     * which is what opens the dialog and starts the polling. Whether a half
     * failed is no longer this answer's to tell — the run says it, when it
     * settles.
     */
    public function asController(LegalCase $legalCase, ActionRequest $request): RedirectResponse
    {
        $this->handle($legalCase, $this->tabsOf($request));

        return to_route('legal-cases.edit', [
            'legalCase' => $legalCase,
            'etapa' => LegalCaseStep::Review->value,
        ]);
    }

    /**
     * @return list<ForensicReviewTab>|null
     */
    private function tabsOf(ActionRequest $request): ?array
    {
        $tabs = $request->validated('tabs');

        return is_array($tabs)
            ? array_values(array_map(static fn (string $tab): ForensicReviewTab => ForensicReviewTab::from($tab), $tabs))
            : null;
    }
}
