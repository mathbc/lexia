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
 * another process. A repeated reference is dropped, and the ceiling is applied
 * here and not only in the schema, the way LegalResearchData holds its own.
 *
 * **From the browser**, where each row is a `legal_theme_id` the screen only
 * ever received from us. Validation has already checked that the theme exists
 * (`exists:legal_themes,id`); a theme is public catalogue, so a posted id that
 * the selection never chose is a link to reference data, not a leak.
 *
 * The empty list is meaningful at both ends: it is how a selection that found
 * nothing applicable comes back, and how a lawyer unticks every theme.
 */
final readonly class LegalCaseThemeListData
{
    /**
     * Ceiling on the themes one selection keeps.
     *
     * Five, like the theses: a pleading that leans on more qualified precedents
     * than that is either unusually wide or reading the list as a quota, and the
     * second is the likelier.
     */
    public const int MAX_THEMES = 5;

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
     * @param  array<mixed>  $answer  the agent's `themes` list
     * @param  Collection<int, LegalTheme>  $candidates  what the prompt offered
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

        return new self(array_slice(array_values($themes), 0, self::MAX_THEMES));
    }

    /**
     * The argument `BelongsToMany::sync()` takes: the pivot columns, keyed by
     * the theme id.
     *
     * The account travels here because the pivot class does not stamp it — see
     * LegalCaseTheme.
     *
     * @return array<string, array{account_id: string, reason: string|null}>
     */
    public function toSync(string $accountId): array
    {
        $sync = [];

        foreach ($this->themes as $theme) {
            $sync[$theme->legalThemeId] = ['account_id' => $accountId, 'reason' => $theme->reason];
        }

        return $sync;
    }
}
