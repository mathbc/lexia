<?php

declare(strict_types=1);

namespace App\Domain\CourtDecisions\Data;

use App\Domain\CourtDecisions\Support\CourtDecisionSources;

/**
 * The whole case law a pleading cites, as one value.
 *
 * The sibling of RequirementListData, and it exists for the same reason: the
 * rulings are saved together and never one at a time — the research writes them
 * whole, the seventh step submits them whole — so the list, and not the row, is
 * the argument the saving Action takes.
 *
 * Two filters run on the way in, and neither is decoration.
 *
 * **Unwritten rows are dropped**, as they are for the requests. A decision with
 * no título or no ementa is a reference nobody can use: the columns are NOT
 * NULL, and `CourtDecisionData::isWritten()` is the same test the research
 * already applies — repeated here because this list also arrives from a browser.
 *
 * **The portal guard runs again**, and that is the one worth stating. On the way
 * out of the research, `CourtDecisionResearchData` refuses every ruling whose
 * `source_url` is not a LexML record, and reports it. On the way *in* there is
 * nobody to report to, so a row that fails here is simply not written. Checking
 * twice is cheap and the alternative is not: a posted decision is a row in a
 * table whose whole premise is that every ementa traces back to a page anyone
 * can open, and a payload is not a place where that premise holds by itself.
 *
 * The empty list is meaningful at both ends: it is how a lawyer clears the
 * step, and it is how a search that confirmed nothing comes back.
 */
final readonly class CourtDecisionListData
{
    /**
     * @param  list<CourtDecisionData>  $decisions
     */
    public function __construct(public array $decisions) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        $rows = $validated['court_decisions'] ?? null;

        if (! is_array($rows)) {
            return new self([]);
        }

        $decisions = array_map(
            CourtDecisionData::fromArray(...),
            array_values(array_filter($rows, 'is_array')),
        );

        return new self(array_values(array_filter($decisions, self::isCitable(...))));
    }

    /**
     * The same list as the research produced it, ready to be written.
     *
     * The research has already filtered; passing through the constructor's
     * filters a second time costs nothing and keeps one door into this class
     * rather than two.
     *
     * @param  list<CourtDecisionData>  $decisions
     */
    public static function of(array $decisions): self
    {
        return new self(array_values(array_filter($decisions, self::isCitable(...))));
    }

    /**
     * Whether a ruling can be cited: written, and traceable to its record.
     */
    private static function isCitable(CourtDecisionData $decision): bool
    {
        return $decision->isWritten() && CourtDecisionSources::isRecord($decision->sourceUrl);
    }
}
