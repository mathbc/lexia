<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions\Concerns;

/**
 * The queue the waits on a provider run in, kept apart from everything else.
 *
 * The first attempt at a queue here shared the `default` one with
 * EmbedCatalogs and a single `queue:listen`: a research that holds a process
 * for minutes made the next lawyer's smart fill wait behind it, and with no
 * worker up at all every screen spun until its ceiling. So the inference jobs
 * have a queue of their own, `inference`, served by a worker of its own — in
 * `composer dev` and in production alike (see "A fila das inferências" in
 * CLAUDE.md).
 *
 * Once, never retried: every one of these jobs spends a paid inference, and
 * its failure is written where the screen reads it — the button that asks
 * again is the retry. Each Action sets its own `$jobTimeout`, above
 * `concurrency.timeout` (840 s) so that a child is killed by the driver before
 * the job is killed by the worker, and below the database connection's
 * `retry_after` (1900 s) so that a running job is never handed to a second
 * worker.
 */
trait RunsOnInferenceQueue
{
    public string $jobQueue = 'inference';

    public int $jobTries = 1;
}
