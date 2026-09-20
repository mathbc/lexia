<?php

declare(strict_types=1);

namespace App\Domain\LegalPleadings\Actions;

use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalPleadings\Models\LegalPleading;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Write the next version of a pleading's draft.
 *
 * The one place a `legal_pleadings` row is ever created, whether the text came
 * from the drafting agent or from the lawyer's keyboard. Both go through here so
 * the numbering has a single implementation — two callers each doing
 * `max(version) + 1` would eventually disagree about what happens when there is
 * no row yet.
 *
 * **Identical text writes nothing and returns null.** Saving a draft the lawyer
 * opened and did not change is the commonest accidental gesture on that screen,
 * and honouring it would fill the history with versions that differ in nothing
 * but their timestamp — which would make the history useless for the one thing
 * it exists for. The null is not a failure: the caller redirects exactly as it
 * would have, and the screen shows the same version it was already showing.
 *
 * The lock is what makes the numbering a fact. `lockForUpdate()` on the latest
 * row holds two concurrent saves in line, so the second reads the version the
 * first just wrote instead of reading the same number and colliding on the unique
 * index. Two browser tabs on one draft is not a hypothetical.
 *
 * `account_id` comes from the pleading and never from the actor — the same rule
 * `SaveLegalCaseForensicReview::blankThesis()` follows, and for the same reason:
 * it is the pleading that says which tenant the document belongs to, and a queued
 * run has no actor to ask.
 */
final class StoreLegalPleadingVersion
{
    use AsAction;

    public function handle(LegalCase $legalCase, string $content): ?LegalPleading
    {
        $content = trim($content);

        if ($content === '') {
            return null;
        }

        return DB::transaction(function () use ($legalCase, $content): ?LegalPleading {
            $latest = $legalCase->pleadings()->lockForUpdate()->first();

            if ($latest instanceof LegalPleading && $latest->content === $content) {
                return null;
            }

            return LegalPleading::create([
                'account_id' => $legalCase->account_id,
                'legal_case_id' => $legalCase->id,
                'content' => $content,
                'version' => ($latest->version ?? 0) + 1,
            ]);
        });
    }
}
