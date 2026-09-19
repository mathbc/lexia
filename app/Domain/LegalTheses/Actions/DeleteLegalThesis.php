<?php

declare(strict_types=1);

namespace App\Domain\LegalTheses\Actions;

use App\Domain\LegalTheses\Models\LegalThesis;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Removes one thesis, leaving its precedents behind.
 *
 * Soft, like every delete in this schema — and that is exactly why the precedents
 * are unhooked here by hand. `legal_precedents.legal_thesis_id` carries
 * `nullOnDelete`, but a soft delete only writes `deleted_at`: the row stays, the
 * database never consults the constraint, and the precedents would keep pointing
 * at a thesis nobody can see. `$thesis->precedents` would then read as grounded
 * while `$precedent->thesis` returned null.
 *
 * The rulings themselves survive. Losing the argument does not unfind them, and
 * a precedent grounding nothing is a visible state somebody fixes.
 *
 * No `asController()` while no route points here.
 */
final class DeleteLegalThesis
{
    use AsAction;

    public function handle(LegalThesis $thesis): void
    {
        $thesis->precedents()->update(['legal_thesis_id' => null]);

        $thesis->delete();
    }
}
