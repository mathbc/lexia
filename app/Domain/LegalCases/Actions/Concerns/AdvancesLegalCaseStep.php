<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions\Concerns;

use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;

/**
 * The one rule every step-saving Action shares: how far the pleading has got.
 *
 * `current_step` is a high-water mark — the furthest step reached — and not the
 * last step edited. The difference is the whole point. The form's timeline
 * unlocks everything up to this value, so under "last edited" a lawyer going
 * back to fix a client's name would find the facts they had already written
 * locked away behind a step they no longer stand on. The listing would lie too,
 * reporting "Dados básicos" for a pleading three quarters written.
 *
 * Hence `furthest()`: saving an earlier step is a correction, not a retreat.
 */
trait AdvancesLegalCaseStep
{
    protected function advanceTo(LegalCase $legalCase, LegalCaseStep $target): void
    {
        $legalCase->update([
            'current_step' => $legalCase->current_step->furthest($target),
        ]);
    }
}
