<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Models\Concerns;

use App\Domain\LegalCases\Enums\InferenceRunStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

/**
 * The life of a row that a queue worker answers: queued, running, and settled.
 *
 * Shared by AssistedFill and ResearchRun, the two things the screen waits on.
 * Three rules, each one paid for by the first attempt at this queue — which
 * left a screen spinning for seventeen minutes because no worker was up.
 *
 * **The screen is told the effective state, not the stored one.** A run that
 * no worker picked up within `ai.queue.pickup_timeout` never started, and one
 * that has been running for longer than its job may live was killed with its
 * worker. Both are stored as they were left, and both are failures: the
 * stored state of an abandoned row would hold the screen locked forever.
 *
 * **Every transition is conditional.** `start()` moves a row out of `queued`,
 * `settle()` out of `running`, `expire()` out of whatever it was left in — each
 * one an `UPDATE … WHERE status = …`. Whoever notices the outcome first (the
 * job, its `jobFailed()`, the Action queueing a new run) writes it, and nobody
 * writes over it. That is also what keeps a worker that wakes up late from
 * reviving a run the screen already gave up on: `start()` refuses a row past
 * its pickup time and marks it failed instead.
 *
 * **The account is never read from `auth()`.** The transitions go through
 * `acrossAllAccounts()` and the primary key: the worker has no tenant, and the
 * id is the whole of what it was handed.
 *
 * @property InferenceRunStatus $status
 * @property string|null $failure
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 */
trait TracksInferenceRun
{
    public const string NOT_STARTED = 'A análise não começou: a fila de processamento não está ativa. Tente novamente em instantes.';

    public const string INTERRUPTED = 'A análise foi interrompida antes de terminar. Tente novamente em instantes.';

    /**
     * How long a `running` row is believed before the margin below: the
     * timeout of the job that runs it.
     */
    abstract public function timeout(): int;

    /**
     * What the worker may still be doing after its job's timeout — the kill
     * signal, `jobFailed()`, the last write.
     */
    private const int INTERRUPTION_MARGIN = 120;

    public static function pickupTimeout(): int
    {
        return (int) config('ai.queue.pickup_timeout');
    }

    public function effectiveStatus(): InferenceRunStatus
    {
        return $this->isStale() ? InferenceRunStatus::Failed : $this->status;
    }

    public function effectiveFailure(): ?string
    {
        return match (true) {
            $this->neverStarted() => self::NOT_STARTED,
            $this->interrupted() => self::INTERRUPTED,
            default => $this->failure,
        };
    }

    public function isPending(): bool
    {
        return $this->effectiveStatus()->isPending();
    }

    public function isStale(): bool
    {
        return $this->neverStarted() || $this->interrupted();
    }

    /**
     * The worker's claim on the row. False when the row is no longer queued, or
     * when it waited past its pickup time — and then it is marked failed, so
     * the screen and the row agree.
     */
    public function start(): bool
    {
        if ($this->neverStarted()) {
            $this->expire();

            return false;
        }

        return $this->transition(InferenceRunStatus::Queued, [
            'status' => InferenceRunStatus::Running,
            'started_at' => now(),
        ]);
    }

    /**
     * The outcome of a running row, written once.
     *
     * @param  array<string, mixed>  $attributes  anything else the outcome carries
     */
    public function settle(InferenceRunStatus $status, ?string $failure = null, array $attributes = []): bool
    {
        return $this->transition(InferenceRunStatus::Running, [
            ...$attributes,
            'status' => $status,
            'failure' => $failure,
            'finished_at' => now(),
        ]);
    }

    /**
     * The effective failure of an abandoned row, made the stored one.
     */
    public function expire(): bool
    {
        return $this->isStale() && $this->transition($this->status, [
            'status' => InferenceRunStatus::Failed,
            'failure' => $this->effectiveFailure(),
            'finished_at' => now(),
        ]);
    }

    private function neverStarted(): bool
    {
        return $this->status === InferenceRunStatus::Queued
            && $this->created_at?->addSeconds(self::pickupTimeout())->isPast() === true;
    }

    private function interrupted(): bool
    {
        $since = $this->started_at ?? $this->created_at;

        return $this->status === InferenceRunStatus::Running
            && $since?->addSeconds($this->timeout() + self::INTERRUPTION_MARGIN)->isPast() === true;
    }

    /**
     * One conditional update, cast by the model and written by the query.
     *
     * The values go through `forceFill()` first so the casts apply — the enum
     * becomes its value, the array becomes json —, and only then into an
     * `UPDATE` guarded by the state the row must still be in. On a miss the
     * model is reloaded, so it never holds a state the database refused — and
     * a row that is gone (its pleading deleted, the cascade with it) leaves the
     * model as it was rather than throwing from inside a job's `catch`.
     *
     * @param  array<string, mixed>  $values
     */
    private function transition(InferenceRunStatus $from, array $values): bool
    {
        $this->forceFill($values);

        $moved = static::acrossAllAccounts()
            ->whereKey($this->getKey())
            ->where('status', $from->value)
            ->update(Arr::only($this->getAttributes(), array_keys($values))) > 0;

        if ($moved) {
            $this->syncOriginal();

            return true;
        }

        $stored = static::acrossAllAccounts()->find($this->getKey());
        $this->setRawAttributes($stored instanceof self ? $stored->getAttributes() : $this->getOriginal(), true);

        return false;
    }
}
