<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Enums;

/**
 * Where a queued inference stands — a smart fill or one research of a
 * pleading.
 *
 * `queued` and `running` are the two states the screen waits on, and keeping
 * them apart is what lets it say why it is waiting: a run nobody has picked up
 * is a worker problem, a run that started is the provider taking its time.
 *
 * `refused` belongs to the smart fill alone: the screening read the text and
 * found no legal matter in it. It is an answer about the input, not a failure,
 * and the form shows it under the facts field rather than as an alert.
 *
 * These are the stored states. What the screen is told is the *effective*
 * state, which turns a run left behind — never picked up, or killed with its
 * worker — into `failed`; see TracksInferenceRun.
 */
enum InferenceRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Refused = 'refused';
    case Failed = 'failed';

    public function isPending(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
