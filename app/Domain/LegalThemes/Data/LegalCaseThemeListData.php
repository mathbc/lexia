<?php

declare(strict_types=1);

namespace App\Domain\LegalThemes\Data;

use App\Domain\LegalThemes\Models\LegalTheme;
use Illuminate\Support\Collection;

/**
 * Every STJ theme a pleading leans on, as one value.
 *
 * The sibling of CourtDecisionListData: the links are written together and
 * never one at a time — the selection writes them whole, the "Concluir"
 * submits the ones the lawyer kept — so the list, and not the row, is what
 * SaveLegalCaseThemes takes. It has two doors because two callers fill it.
 *
 * **From the agent**, where the answer names each theme by the `reference` the
 * prompt showed (`theme-1016`). The schema's `enum` already makes an unknown
 * reference impossible to emit; resolving against the candidates anyway costs
 * one lookup and keeps this class from trusting a guarantee that lives in
 * another process. A repeated reference is dropped, and both bounds are
 * applied here and not only in the schema, the way LegalResearchData holds its
 * own: the ceiling cuts, and the floor tops the list up with the candidates
 * the agent left out, in retrieval order, under a reason that says so.
 *
 * **From the browser**, where each row is a `legal_theme_id` the screen only
 * ever received from us. Validation has already checked that the theme exists
 * (`exists:legal_themes,id`); a theme is public catalogue, so a posted id that
 * the selection never chose is a link to reference data, not a leak.
 *
 * **The order is the ranking**, at both doors. The agent returns the themes
 * from the most to the least relevant, the screen draws them in that order and
 * posts back the ones the lawyer kept in the same order, and `toSync()` writes
 * each one's index as the pivot's `position`. An empty list only comes from the
 * browser now — it is how a lawyer unticks every theme.
 */
final readonly class LegalCaseThemeListData
{
    /**
     * The fewest themes one selection returns, when the retrieval found that
     * many.
     *
     * A floor, because the selection ranks rather than filters: a theme that
     * turns out not to serve costs the lawyer one untick, and a tab that came
     * back empty cannot be checked at all — "nothing applies" and "the search
     * missed" look the same from the screen.
     */
    public const int MIN_THEMES = 3;

    /**
     * Ceiling on the themes one selection keeps.
     *
     * Eight: enough room for a case that touches the typification, the evidence
     * and the sentence at once, and few enough that the list is read and not
     * skimmed. Past that the ranking is padding.
     */
    public const int MAX_THEMES = 8;

    /**
     * What the floor writes beside a theme the agent did not choose.
     */
    public const string UNRANKED_REASON = 'Incluído pela proximidade com as questões de direito do caso, sem análise do '
        .'agente: confira se ele se aplica antes de mantê-lo.';

    /**
     * @param  list<LegalCaseThemeData>  $themes
     */
    public function __construct(public array $themes) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        $rows = $validated['themes'] ?? null;

        if (! is_array($rows)) {
            return new self([]);
        }

        $themes = [];

        foreach (array_filter($rows, 'is_array') as $row) {
            $id = $row['legal_theme_id'] ?? null;

            if (is_string($id) && $id !== '') {
                $themes[$id] = LegalCaseThemeData::of($id, $row['reason'] ?? null);
            }
        }

        return new self(array_values($themes));
    }

    /**
     * @param  array<mixed>  $answer  the agent's `themes` list, most relevant first
     * @param  Collection<int, LegalTheme>  $candidates  what the prompt offered, in retrieval order
     */
    public static function fromAgent(array $answer, Collection $candidates): self
    {
        $ids = $candidates->mapWithKeys(
            static fn (LegalTheme $theme): array => [$theme->reference() => $theme->id],
        );

        $themes = [];

        foreach (array_filter($answer, 'is_array') as $row) {
            $id = $ids->get((string) ($row['reference'] ?? ''));

            if (is_string($id) && ! isset($themes[$id])) {
                $themes[$id] = LegalCaseThemeData::of($id, $row['reason'] ?? null);
            }
        }

        return new self(array_slice(array_values(self::floored($themes, $ids->values()->all())), 0, self::MAX_THEMES));
    }

    /**
     * The agent's themes, topped up to the floor with the candidates it left
     * out — after its own, never between them, and in retrieval order.
     *
     * @param  array<string, LegalCaseThemeData>  $themes  keyed by theme id
     * @param  list<string>  $candidates  every candidate id, in retrieval order
     * @return array<string, LegalCaseThemeData>
     */
    private static function floored(array $themes, array $candidates): array
    {
        foreach ($candidates as $id) {
            if (count($themes) >= self::MIN_THEMES) {
                break;
            }

            $themes[$id] ??= new LegalCaseThemeData($id, self::UNRANKED_REASON);
        }

        return $themes;
    }

    /**
     * The argument `BelongsToMany::sync()` takes: the pivot columns, keyed by
     * the theme id.
     *
     * The account travels here because the pivot class does not stamp it — see
     * LegalCaseTheme. The position is the index, so the order this list was
     * built in is the order the screen reads back.
     *
     * @return array<string, array{account_id: string, reason: string|null, position: int}>
     */
    public function toSync(string $accountId): array
    {
        $sync = [];

        foreach ($this->themes as $position => $theme) {
            $sync[$theme->legalThemeId] = ['account_id' => $accountId, 'reason' => $theme->reason, 'position' => $position];
        }

        return $sync;
    }
}
