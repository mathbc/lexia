<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalPleadings\Models\LegalPleading;
use App\Domain\LegalPleadings\Support\PleadingLetterhead;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The second tab of a pleading: the document itself.
 *
 * The tab beside the wizard, and a real URL rather than local state — the same
 * rule the account's two tabs follow, and for the same reason: a lawyer reading
 * a draft can reload, link to it and come back to it.
 *
 * One version's text is sent: the latest, or the one `?versao` names — the
 * select at the top of the card is a link, for the reason the tab itself is.
 * The others travel as `versions`, number and date only, which is all the
 * select draws: shipping every version's prose on every page load would mean
 * sending several pages per version to show one. Newest first, so the first
 * entry is the current text.
 *
 * An older version is **read, not edited**. A save is always about the current
 * text (see SaveLegalPleadingContent), and editing version 2 to store version 4
 * would be a branch; the screen hides the editing controls instead of offering
 * a save that means something else. What it keeps is the export, which prints
 * the version on screen.
 *
 * `pleading` is null when the drafting failed — see FinalizeLegalCase, which
 * lets that happen rather than losing the forensic review with it. The screen
 * says so and offers to try again. With a version in hand `can.generate` is the
 * "Gerar novamente" button instead: the agent writes the next version, and the
 * lawyer's edits stay in the table as the previous one — see
 * GenerateLegalPleading. `can.export` is simply "there is a version": the PDF
 * and the DOCX print the latest saved one, and viewing the tab already required
 * the `view` their routes check.
 */
final class ShowLegalPleading
{
    use AsAction;

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('view', $request->route('legalCase'));
    }

    public function asController(LegalCase $legalCase, ActionRequest $request): Response
    {
        $legalCase->loadMissing(['account', 'customer', 'proceduralClass']);

        $pleading = $legalCase->pleadingVersion($request->integer('versao') ?: null);

        return Inertia::render('legal-cases/pleading', [
            'legalCase' => [
                'id' => $legalCase->id,
                'customer_name' => $legalCase->customer->displayName(),
                'procedural_class' => $legalCase->proceduralClass->name,
                'is_draft' => $legalCase->is_draft,
                'current_step' => $legalCase->current_step->value,
            ],
            'pleading' => $pleading instanceof LegalPleading
                ? [
                    'id' => $pleading->id,
                    'content' => $pleading->content,
                    'version' => $pleading->version,
                    'created_at' => $pleading->created_at?->toIso8601String(),
                    'placeholders' => $pleading->placeholders(),
                ]
                : null,
            'versions' => $legalCase->pleadings()
                ->get(['version', 'created_at'])
                ->map(fn (LegalPleading $version): array => [
                    'version' => $version->version,
                    'created_at' => $version->created_at?->toIso8601String(),
                ])
                ->all(),
            'letterhead' => PleadingLetterhead::for($legalCase->account, $request->user()),
            'can' => [
                'update' => $request->user()->can('update', $legalCase),
                // A porta do agente: gerar a primeira, ou gerar de novo por cima
                // — que grava a versão seguinte e não apaga nada.
                'generate' => $request->user()->can('update', $legalCase),
                'export' => $pleading instanceof LegalPleading,
            ],
        ]);
    }
}
