<?php

declare(strict_types=1);

namespace App\Domain\AgentsExecutionLogs\Actions;

use App\Domain\AgentsExecutionLogs\Data\AgentFailureData;
use App\Domain\AgentsExecutionLogs\Models\AgentsExecutionLog;
use App\Domain\AiModels\Support\DefaultModels;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * Writes down an agent that fell: which one, against which model, with what
 * status and what the provider said.
 *
 * It sits next to `report()`, never in its place. The log keeps the stack
 * trace, which is what fixes a bug; this row keeps the answer to the question
 * operation asks first — is it the STJ again, or the Gemini quota, or one agent
 * that broke —, which a stack trace answers three `previous` deep.
 *
 * **It never throws.** It is called from inside a `catch`, about a failure that
 * already happened, and a log that failed would replace the cause with its own:
 * the database down, the column too short. So its own failure goes to
 * `report()` and the caller carries on with the one it was handling.
 *
 * `$operation` names the stage (`forensic_review.theses`, `assisted_fill`), and
 * `$fallbackAgent` is who to blame when no agent can be read from the failure —
 * the child process killed by its clock has no stack. The Action that held the
 * stage is the honest answer there.
 */
final class RecordAgentFailure
{
    use AsAction;

    public function handle(
        string $operation,
        Throwable $failure,
        string $fallbackAgent,
        ?string $accountId = null,
        ?string $legalCaseId = null,
    ): ?AgentsExecutionLog {
        try {
            $data = AgentFailureData::from($failure, $fallbackAgent);

            return AgentsExecutionLog::query()->create([
                'account_id' => $accountId,
                'legal_case_id' => $legalCaseId,
                'agent' => $data->agent,
                'operation' => $operation,
                'http_status' => $data->httpStatus,
                'message' => $data->message,
                'error' => $data->error,
                'model' => $this->model(),
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * The text model every agent was answering on, as the catalogue names it.
     *
     * Read here and not passed in, because no caller knows it — `DefaultModels`
     * is the only one who does. A catalogue with no default is itself the
     * failure being logged, so it answers null instead of throwing again.
     */
    private function model(): ?string
    {
        try {
            $model = app(DefaultModels::class)->text();

            return "{$model->provider->value}:{$model->name}";
        } catch (Throwable) {
            return null;
        }
    }
}
