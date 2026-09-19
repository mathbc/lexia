<?php

declare(strict_types=1);

namespace App\Domain\LegalPrecedents\Actions;

use App\Domain\LegalPrecedents\Models\LegalPrecedent;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Removes one ruling.
 *
 * Soft, like every delete in this schema, and with nothing to unhook: a
 * precedent is the leaf of the forensic review — the thesis does not point back
 * at it, the relation reads the other way.
 *
 * No `asController()` while no route points here.
 */
final class DeleteLegalPrecedent
{
    use AsAction;

    public function handle(LegalPrecedent $precedent): void
    {
        $precedent->delete();
    }
}
