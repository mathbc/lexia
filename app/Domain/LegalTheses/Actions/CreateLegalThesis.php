<?php

declare(strict_types=1);

namespace App\Domain\LegalTheses\Actions;

use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalTheses\Data\LegalThesisData;
use App\Domain\LegalTheses\Models\LegalThesis;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Registers one thesis on a pleading.
 *
 * A component on its own, next to SaveLegalCaseForensicReview rather than
 * inside it: that one writes the step, which is both lists at once and the diff
 * between what was there and what came back. This one adds a single row and
 * touches nothing else, which is what a caller holding one thesis needs — the
 * forensic review agent, when it exists, appending its findings one at a time.
 *
 * No `asController()` while no route points here, exactly as its siblings in
 * LegalCases\Actions. `::run()` is the entry point.
 *
 * The account comes from the pleading and not from the actor, the same rule
 * `SaveLegalCaseForensicReview::blankThesis()` follows: it is the pleading that
 * says which tenant the argument belongs to, and a queued agent has no actor at
 * all.
 */
final class CreateLegalThesis
{
    use AsAction;

    public function handle(LegalCase $legalCase, LegalThesisData $data): LegalThesis
    {
        $thesis = new LegalThesis([
            'account_id' => $legalCase->account_id,
            'legal_case_id' => $legalCase->id,
        ]);

        // toArray() não devolve `id`: a chave é cunhada pelo HasUuids, nunca
        // colada do payload nem da sugestão de um modelo.
        $thesis->fill($data->toArray())->save();

        return $thesis;
    }
}
