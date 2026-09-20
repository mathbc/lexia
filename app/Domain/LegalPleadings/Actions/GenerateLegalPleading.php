<?php

declare(strict_types=1);

namespace App\Domain\LegalPleadings\Actions;

use App\Domain\LegalCases\Actions\DraftLegalPleading;
use App\Domain\LegalCases\Models\LegalCase;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * Ask the agent for the document again, after the first attempt failed.
 *
 * **Recovery, and not regeneration.** FinalizeLegalCase deliberately lets the
 * drafting fail without taking the rest of the conclusion with it, which leaves
 * a registered pleading whose Minuta tab is empty. This is the way back from
 * that state, and it is the only door to the agent after the sixth step.
 *
 * So it refuses when a version already exists, and the refusal is the point
 * rather than a guard against a mistake. The screen does not offer the button
 * once there is a draft — `ShowLegalPleading` computes `can.generate` from the
 * same condition — and a route that would happily rewrite the document anyway
 * is a side door into a gesture the product has decided not to offer. Editing is
 * how a draft changes; the agent's prose is never written over the lawyer's.
 *
 * The failure message goes to the session rather than to an exception page: the
 * lawyer asked for a retry and the retry did not work, which is news about the
 * provider and not an error in the application. `report()` is what keeps the
 * cause reaching the log.
 */
final class GenerateLegalPleading
{
    use AsAction;

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('update', $request->route('legalCase'));
    }

    public function asController(LegalCase $legalCase, ActionRequest $request): RedirectResponse
    {
        $destination = to_route('legal-cases.pleading', ['legalCase' => $legalCase]);

        if ($legalCase->pleadings()->exists()) {
            return $destination->with('error', 'Esta peça já tem uma minuta.');
        }

        try {
            DraftLegalPleading::run($legalCase, $request->user());
        } catch (Throwable $e) {
            report($e);

            return $destination->with(
                'error',
                'Não foi possível redigir a minuta agora. Tente novamente em instantes.',
            );
        }

        return $destination->with('success', 'Minuta gerada.');
    }
}
