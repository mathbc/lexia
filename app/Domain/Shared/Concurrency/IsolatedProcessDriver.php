<?php

declare(strict_types=1);

namespace App\Domain\Shared\Concurrency;

use Closure;
use Illuminate\Concurrency\ProcessDriver;
use Illuminate\Console\Application;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\InvokedProcess;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Arr;
use Laravel\SerializableClosure\SerializableClosure;
use RuntimeException;
use Throwable;

/**
 * The framework's process driver, with one promise changed: a task that does
 * not come back is null under its own key, and the others still come back.
 *
 * `AppServiceProvider` registers it under the name `process`, so
 * `CONCURRENCY_DRIVER=process` means this class and `sync` still means the
 * framework's.
 *
 * ## What the framework's driver did, measured
 *
 * Two things, and the second is the one that hurt. First, it never calls
 * `timeout()`, so every child inherits `PendingProcess`'s 60 seconds — less
 * than the thesis research alone takes, with the Gemini opening the portals
 * and the transcriber after it:
 *
 *     The process "'php' 'artisan' invoke-serialized-closure"
 *     exceeded the timeout of 60 seconds.
 *
 * Second, that exception is thrown by the **pool**, in the parent, and not by
 * the task, in the child. The `try/catch` that ClassifyLegalCase and
 * ResearchLegalCaseForensicReview keep inside each closure never sees it, and
 * the whole array is discarded — the theme selection that had come back in
 * thirty seconds went down with the theses that timed out.
 *
 * Waiting on each child separately would not have been enough. A child writes
 * its result to a pipe, and a result bigger than the pipe's buffer blocks the
 * child on `write()` until someone reads it; the pool read one child at a time,
 * so the fast one sat blocked behind the slow one and was killed by its own
 * clock when its turn came. Reproduced with a 300 KB result and a three-second
 * timeout: both halves timed out at 3.1 s, and only one of them had any work
 * left to do.
 *
 * ## What it does instead
 *
 * Every child starts at once and is polled in the same loop, and polling is
 * what drains its pipes — `running()` reads what the child wrote so far, so
 * no child waits on another to be read. Each child has its own clock, from
 * `concurrency.timeout`, and one that fails in any way the child could not
 * catch — the clock, a fatal error, an exit code, an unreadable answer — is
 * reported with its key and settles as null.
 *
 * Null is exactly what `stage()` and `attempt()` already answer from inside
 * the child when an agent falls, so the callers read one convention for both.
 * They still need their own `try/catch`: `sync` runs the closures in this
 * process, where an escaped exception reaches the caller, and inside a child
 * it is `report()` that logs the real stack trace, which the parent never has.
 */
final class IsolatedProcessDriver extends ProcessDriver
{
    /** Between two looks at the children: nothing run here answers in under a second. */
    private const POLL_INTERVAL_MICROSECONDS = 50_000;

    public function __construct(ProcessFactory $processFactory, private readonly int $timeout)
    {
        parent::__construct($processFactory);
    }

    /**
     * @param  Closure|array<array-key, Closure>  $tasks
     * @return array<array-key, mixed>
     */
    public function run(Closure|array $tasks): array
    {
        $running = array_map($this->start(...), Arr::wrap($tasks));

        // Pré-preenchido para que a ordem das chaves seja a pedida, e não a de
        // chegada.
        $settled = array_fill_keys(array_keys($running), null);

        while ($running !== []) {
            foreach ($running as $key => $process) {
                if (! $this->settle($key, $process, $settled)) {
                    continue;
                }

                unset($running[$key]);
            }

            if ($running !== []) {
                usleep(self::POLL_INTERVAL_MICROSECONDS);
            }
        }

        return $settled;
    }

    private function start(Closure $task): InvokedProcess
    {
        return $this->processFactory->newPendingProcess()
            ->path(base_path())
            ->timeout($this->timeout)
            ->env([
                'LARAVEL_INVOKABLE_CLOSURE' => base64_encode(serialize(new SerializableClosure($task))),
            ])
            ->start(Application::formatCommandString('invoke-serialized-closure'));
    }

    /**
     * Whether this child is done — with a result, or with a null in its place.
     *
     * @param  array<array-key, mixed>  $settled
     */
    private function settle(int|string $key, InvokedProcess $process, array &$settled): bool
    {
        try {
            // `running()` antes do relógio: é ele que esvazia os pipes, e um
            // filho que acabou de terminar não deve ser abatido por ter
            // terminado tarde.
            if ($process->running()) {
                $process->ensureNotTimedOut();

                return false;
            }

            $settled[$key] = $this->resultOf($process->wait());
        } catch (Throwable $e) {
            report(new RuntimeException("A task concorrente [{$key}] não voltou.", previous: $e));
        }

        return true;
    }

    /**
     * The child's answer, read the way the framework's driver reads it.
     */
    private function resultOf(ProcessResult $result): mixed
    {
        if ($result->failed()) {
            throw new RuntimeException("O processo terminou com o código [{$result->exitCode()}]: {$result->errorOutput()}");
        }

        $output = $result->output();

        // O mesmo corte do framework: o que vier depois de um cabeçalho gzip
        // não é a resposta da task.
        if (($pos = strpos($output, "\x1f\x8b")) !== false) {
            $output = substr($output, 0, $pos);
        }

        /** @var array{successful: bool, result?: string, exception?: string, message?: string} $answer */
        $answer = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        if (! $answer['successful']) {
            // O filho já relatou a exceção com o stack trace de verdade; aqui
            // só sobra o nome dela.
            throw new RuntimeException("{$answer['exception']}: {$answer['message']}");
        }

        return unserialize((string) $answer['result']);
    }
}
