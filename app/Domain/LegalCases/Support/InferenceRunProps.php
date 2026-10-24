<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Support;

use App\Domain\LegalCases\Enums\InferenceRunStatus;
use App\Domain\LegalCases\Models\AssistedFill;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalCases\Models\ResearchRun;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The queued inferences, shaped for the screens that wait on them.
 *
 * Three projections and one rule: **the status is the effective one**. A row a
 * worker abandoned is still `queued` or `running` in the database, and the
 * screen that believed it would stay locked for good; TracksInferenceRun turns
 * it into a failure with a sentence the screen can show, and that is what goes
 * out.
 *
 * Each projection is small on purpose. The screens poll them every few seconds
 * as partial reloads, and a poll should cost a query, not the pleading.
 */
final class InferenceRunProps
{
    /**
     * The smart fill the new pleading's screen is waiting on, named by the
     * `?preenchimento` query string.
     *
     * Not found unless it is the actor's own — a malformed id included, which
     * would otherwise reach Postgres as a uuid it refuses. The facts go out
     * because the screen draws them behind the dialog and carries them to the
     * hand-off; they are the actor's own narrative, and the row drops them once
     * the pleading holds them. `result` goes out only for the one path that
     * reads it: finished, and no pleading to open.
     *
     * @return array<string, mixed>
     */
    public static function fill(string $id): array
    {
        $fill = self::ownFill($id);
        $status = $fill->effectiveStatus();

        return [
            'id' => $fill->id,
            'status' => $status->value,
            'customer_id' => $fill->customer_id,
            'facts' => $fill->facts,
            'legal_case_id' => $fill->legal_case_id,
            'result' => $status === InferenceRunStatus::Completed && $fill->legal_case_id === null
                ? $fill->result
                : null,
            'failure' => $fill->effectiveFailure(),
        ];
    }

    /**
     * What the smart fill form needs to put itself back after a fill that did
     * not end in a pleading: the client, the narrative, and why.
     *
     * Null for anything that is not a refusal or a failure — a fill still
     * running, or one that saved its pleading, has nothing to put back.
     * `field` says where the sentence goes: under the facts for the screening's
     * verdict, which is about the text; in the alert for everything else.
     *
     * @return array{customer_id: string|null, facts: string, message: string, field: 'facts'|null}|null
     */
    public static function retry(string $id): ?array
    {
        $fill = self::ownFill($id);
        $status = $fill->effectiveStatus();

        if ($status !== InferenceRunStatus::Refused && $status !== InferenceRunStatus::Failed) {
            return null;
        }

        return [
            'customer_id' => $fill->customer_id,
            'facts' => (string) $fill->facts,
            'message' => (string) $fill->effectiveFailure(),
            'field' => $status === InferenceRunStatus::Refused ? 'facts' : null,
        ];
    }

    /**
     * The latest run of each subject: pending ones lock the step, failed ones
     * are reported on their tab.
     *
     * Completed runs go out too, and not because the screen shows them. The
     * step fires a research when a subject's marker is null **and it has no
     * run**; the marker arrives with `legalCase`, the run with this prop, and
     * the screen polls this one alone. Were a completed run left out, the poll
     * that sees it finish would show the subject with no run while the marker
     * is still null in the props on screen — and the step would fire the paid
     * research again in the instant before the pleading reloads.
     *
     * `failure` goes out only when it says something the tab's own alert does
     * not. A research that fell is already explained there, at length and in
     * the tab's words; what the alert cannot know is that the queue never
     * picked the run up, or that the worker died with it — and those are the
     * ones that send somebody to look at the worker rather than at the STJ.
     *
     * @return array<string, array{status: string, failure: string|null}>
     */
    public static function researchRuns(LegalCase $legalCase): array
    {
        return $legalCase->researchRuns()
            ->get()
            ->unique(static fn (ResearchRun $run): string => $run->subject->value)
            ->mapWithKeys(static fn (ResearchRun $run): array => [
                $run->subject->value => [
                    'status' => $run->effectiveStatus()->value,
                    'failure' => $run->effectiveFailure() === $run->subject->failure()
                        ? null
                        : $run->effectiveFailure(),
                ],
            ])
            ->all();
    }

    private static function ownFill(string $id): AssistedFill
    {
        abort_unless(Str::isUuid($id), 404);

        $fill = AssistedFill::query()->findOrFail($id);

        Gate::authorize('view', $fill);

        return $fill;
    }
}
