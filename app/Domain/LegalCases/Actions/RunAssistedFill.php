<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\Customers\Models\Customer;
use App\Domain\LegalCases\Actions\Concerns\RunsOnInferenceQueue;
use App\Domain\LegalCases\Data\LegalCaseClassification;
use App\Domain\LegalCases\Enums\InferenceRunStatus;
use App\Domain\LegalCases\Exceptions\InadmissibleFactsException;
use App\Domain\LegalCases\Models\AssistedFill;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\Shared\Tenancy\TenantContext;
use App\Domain\Users\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * The smart fill, run in the `inference` queue: the request asks for it, a
 * worker does it, and the screen of the new pleading waits for it.
 *
 * ## Why a queue, again
 *
 * `ClassifyLegalCase` is a screening, a concurrent block of five stages and an
 * addressing chain — minutes of inference in the worst case. Behind the
 * Cloudflare of production the request was cut with a 504 long before
 * `AllowLongInference` had a say: the pleading was even saved, but the answer
 * never reached the browser, and the screen never moved.
 *
 * The queue was tried here once and taken out, because no worker was up and
 * the screen spun until its ceiling. What is different now is operational and
 * written into the code: the queue is `inference`, apart from the `default`
 * one (RunsOnInferenceQueue), and a fill nobody picks up within
 * `ai.queue.pickup_timeout` is told to the screen as failed in minutes, not in
 * seventeen (TracksInferenceRun).
 *
 * ## The two halves
 *
 * `handle()` is the use case without HTTP: it writes the AssistedFill as
 * `queued` and queues the job with its id — the narrative stays in the row, and
 * never in the job payload. `asController()` validates what the old route
 * validated and redirects to `/pecas/nova?preenchimento={id}`, the screen of
 * the new pleading, which locks behind the analysis dialog and polls the row.
 *
 * `asJob()` is what the request used to do inline: `ClassifyLegalCase::run()` —
 * whose `handle()` does not change a line, and is still what the agent tests
 * exercise —, then the draft by `CreateAssistedLegalCase`. The outcome goes to
 * the row and never to an exception, so the screen always has something to
 * read:
 *
 * - **completed**, with the pleading's id — the screen opens `/pecas/{id}/editar`;
 *   or without one, when there is no class to save it under (the column is NOT
 *   NULL) or the save fell, and then `result` carries the framing to
 *   `/pecas/nova` through the `sessionStorage` hand-off, as before;
 * - **refused**, the screening's verdict, which the form shows under the facts;
 * - **failed**, the 503 of before, in the same words.
 *
 * The job runs under the fill's account, by `TenantContext::actingAs()`: the
 * worker has no session, and the client is read inside the tenant scope exactly
 * as the request read it.
 */
final class RunAssistedFill
{
    use AsAction;
    use RunsOnInferenceQueue;

    /**
     * The screening, the block under `concurrency.timeout` (840 s) and the
     * addressing chain after it, with room to save — far above what a run on
     * the cloud takes, and below the queue's `retry_after` (1900 s).
     */
    public const int TIMEOUT = 1500;

    public const string FAILURE = 'Não foi possível enquadrar o caso agora. Tente novamente em instantes.';

    public int $jobTimeout = self::TIMEOUT;

    public function handle(User $user, ?Customer $customer, string $facts): AssistedFill
    {
        $fill = AssistedFill::query()->create([
            'account_id' => $user->account_id,
            'user_id' => $user->id,
            'customer_id' => $customer?->id,
            'facts' => $facts,
            'status' => InferenceRunStatus::Queued,
        ]);

        self::dispatch($fill->id);

        return $fill;
    }

    public function asJob(string $assistedFillId): void
    {
        $fill = AssistedFill::acrossAllAccounts()->find($assistedFillId);

        if (! $fill instanceof AssistedFill || ! $fill->start()) {
            return;
        }

        app(TenantContext::class)->actingAs($fill->account_id, fn () => $this->fill($fill));
    }

    /**
     * The worker died with the fill in its hands — its timeout, or a deploy.
     * The worker reports the exception; this only tells the screen.
     */
    public function jobFailed(Throwable $e, string $assistedFillId): void
    {
        AssistedFill::acrossAllAccounts()
            ->find($assistedFillId)
            ?->settle(InferenceRunStatus::Failed, AssistedFill::INTERRUPTED);
    }

    private function fill(AssistedFill $fill): void
    {
        $customer = $fill->customer_id === null
            ? null
            : Customer::query()->find($fill->customer_id);

        try {
            $classification = ClassifyLegalCase::run((string) $fill->facts, $customer);
        } catch (InadmissibleFactsException $e) {
            $this->refuse($fill, $e);

            return;
        } catch (Throwable $e) {
            // Sem `RecordAgentFailure` aqui: a triagem e o enquadramento já
            // anotaram a própria queda, e o que chega é ela ou o embrulho dela.
            report($e);
            $fill->settle(InferenceRunStatus::Failed, self::FAILURE);

            return;
        }

        $legalCase = $this->draft($fill, $customer, $classification);

        $fill->settle(InferenceRunStatus::Completed, attributes: [
            'result' => [...$classification->toArray(), 'legal_case_id' => $legalCase?->id],
            'legal_case_id' => $legalCase?->id,
            // A peça guarda o relato daqui em diante. Sem peça ele fica, porque
            // a entrega para `/pecas/nova` é feita com ele.
            'facts' => $legalCase instanceof LegalCase ? null : $fill->facts,
        ]);
    }

    /**
     * The screening's verdict, which is an answer and not a failure.
     *
     * Sem o texto no log: um falso positivo é o relato de um cliente de
     * verdade. Quem recusa o quê, e quantas vezes, é o que serve para ver um
     * usuário insistindo.
     */
    private function refuse(AssistedFill $fill, InadmissibleFactsException $e): void
    {
        Log::notice('Relato recusado pela triagem.', [
            'user_id' => $fill->user_id,
            'account_id' => $fill->account_id,
            'verdict' => $e->screening->verdict->value,
            'length' => mb_strlen((string) $fill->facts),
        ]);

        $fill->settle(InferenceRunStatus::Refused, $e->getMessage());
    }

    /**
     * The pleading saved as a draft, or null when it cannot be.
     *
     * Sem cliente ou sem classe a recusa é esperada e não vai ao log: a coluna
     * não admite nenhum dos dois nulos, e a tela segue pela entrega. A gravação
     * que falha vai, porque é defeito — e nem ela custa o enquadramento.
     */
    private function draft(AssistedFill $fill, ?Customer $customer, LegalCaseClassification $classification): ?LegalCase
    {
        if ($customer === null || $classification->proceduralClass === null) {
            return null;
        }

        try {
            return CreateAssistedLegalCase::run(
                $fill->account_id,
                $fill->user_id,
                $customer,
                (string) $fill->facts,
                $classification,
            );
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Quem pode abrir uma peça pode pedir o enquadramento de uma: é o mesmo
     * gesto, e a rota é a porta curta para o mesmo formulário.
     */
    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('create', LegalCase::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(ActionRequest $request): array
    {
        return [
            // O piso não julga nada: só poupa a triagem do que nem frase é.
            'facts' => ['required', 'string', 'min:20'],
            // Opcional na regra, e só para o endereçamento e a gravação: sem
            // ele a sexta etapa não roda e a peça não é gravada. Conferido
            // contra a conta do ator — é um id vindo do pedido, e para a
            // equipe LexIA o escopo está aberto.
            'customer_id' => [
                'nullable',
                'uuid',
                Rule::exists('customers', 'id')
                    ->where('account_id', $request->user()->account_id)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return [
            'facts' => 'fatos',
            'customer_id' => 'cliente',
        ];
    }

    public function asController(ActionRequest $request): RedirectResponse
    {
        $customer = $request->filled('customer_id')
            ? Customer::query()->findOrFail($request->string('customer_id')->toString())
            : null;

        $fill = $this->handle($request->user(), $customer, $request->string('facts')->toString());

        return to_route('legal-cases.create', ['preenchimento' => $fill->id]);
    }
}
