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
 * Ask the agent for the document — again after a failure, or over a draft.
 *
 * Two situations share the door. FinalizeLegalCase deliberately lets the
 * drafting fail without taking the rest of the conclusion with it, which leaves
 * a registered pleading whose Minuta tab is empty; and a lawyer who changed the
 * theses, the requests or the client's qualification wants a document that
 * reflects them. Both are the same gesture: write the next version.
 *
 * **Regenerating never overwrites.** DraftLegalPleading stores through
 * StoreLegalPleadingVersion like every edit does, so the lawyer's corrected text
 * stays in `legal_pleadings` as the previous version and the agent's new prose
 * becomes the current one. That is what made the gesture safe to offer: the
 * earlier refusal existed because regenerating looked like writing over the
 * lawyer's work, and an append-only table means it does not. The screen still
 * asks before firing, because the corrections leave the tab even if they stay in
 * the table.
 *
 * The failure message goes to the session rather than to an exception page: the
 * lawyer asked for a draft and the provider did not deliver one, which is news
 * about the provider and not an error in the application. `report()` is what
 * keeps the cause reaching the log, and the current version is untouched.
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
        $existed = $legalCase->pleadings()->exists();

        try {
            DraftLegalPleading::run($legalCase, $request->user());
        } catch (Throwable $e) {
            report($e);

            return $destination->with(
                'error',
                'Não foi possível redigir a minuta agora. Tente novamente em instantes.',
            );
        }

        return $destination->with('success', $existed ? 'Nova versão da minuta gerada.' : 'Minuta gerada.');
    }
}
