<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\LegalCases\Actions\Concerns\AdvancesLegalCaseStep;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalThemes\Data\LegalCaseThemeListData;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Saves which STJ themes a pleading leans on — the sixth step's second tab.
 *
 * The sibling of SaveLegalCaseCourtDecisions, and simpler, because what it
 * writes is a link and not a document: `sync()` on the N-N is the whole diff.
 * Themes that stayed keep their row (and take the posted reason), themes that
 * left are unlinked, themes that arrived are linked.
 *
 * This is where a lawyer's unticking becomes a removal, exactly as it is for
 * the theses and the rulings. The selection links every theme it kept; the tab
 * ticks them all and lets the lawyer untick what does not serve, and the
 * untick lives in the browser until `FinalizeLegalCase` posts the list without
 * it. Removing and not flagging, for the siblings' reason: a pleading leans on
 * the themes it leans on, and "Pesquisar novamente" is how a lawyer who
 * changed their mind gets them back.
 *
 * The unlink is a hard delete — the rows have no soft delete, and the
 * migration says why: they are links to a catalogue that never goes away.
 *
 * No `asController()`: no route points here. The two callers are
 * ResearchLegalCaseForensicReview, which writes what the agent selected, and
 * FinalizeLegalCase, which writes what the lawyer kept — validating with the
 * `rules()` below, delegated rather than restated so the two cannot drift.
 */
final class SaveLegalCaseThemes
{
    use AdvancesLegalCaseStep;
    use AsAction;

    public function handle(LegalCase $legalCase, LegalCaseThemeListData $data): LegalCase
    {
        return DB::transaction(function () use ($legalCase, $data): LegalCase {
            // A conta sai da peça, e não do ator: é a peça que diz a que
            // tenant o vínculo pertence.
            $legalCase->themes()->sync($data->toSync($legalCase->account_id));

            // A mesma marca d'água que a gravação das teses levanta: as duas
            // abas são uma etapa só.
            $this->advanceTo($legalCase, LegalCaseStep::Review);

            return $legalCase->refresh();
        });
    }

    public function authorize(ActionRequest $request): bool
    {
        // A peça, como em toda etapa do assistente: quem pode escrevê-la pode
        // dizer em que temas ela se apoia.
        return $request->user()->can('update', $request->route('legalCase'));
    }

    /**
     * The empty list is valid — it is how a lawyer unticks every theme.
     *
     * `exists` on the theme is the one check that matters: `sync()` would
     * otherwise hit the foreign key and answer with a 500 instead of a message.
     * A theme is public catalogue, so checking that it exists is the whole
     * guard — there is no account for it to belong to.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'themes' => ['present', 'array'],
            'themes.*.legal_theme_id' => ['required', 'uuid', 'distinct', 'exists:legal_themes,id'],
            'themes.*.reason' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return [
            'themes' => 'temas',
            'themes.*.legal_theme_id' => 'tema',
            'themes.*.reason' => 'razão do tema',
        ];
    }
}
