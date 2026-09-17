<?php

declare(strict_types=1);

namespace App\Domain\ProceduralClasses\Queries;

use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use Illuminate\Database\Eloquent\Collection;

/**
 * The classes an agent may choose from once the practice area is known.
 *
 * Sibling of ProceduralClassOptionsQuery, and deliberately not the same thing:
 * that one projects for the picker — jurisdiction tags, `path`, booleans the
 * card draws — and drops the model on the way out. Here the model is the point.
 * The Action hands these rows to the agent and resolves the answer against the
 * very same collection, which is what makes the returned code provably one of
 * the rows it sent; a second query could quietly disagree with the first.
 *
 * Only filing classes: a narrative of facts with no lawsuit yet is an initial
 * pleading, so an appeal, an incident or a cumprimento de sentença cannot be
 * the answer. The filter roughly halves the list — Família goes from 109 to 54
 * — and every candidate removed is one the model can no longer pick by mistake.
 *
 * The exception is `processual-geral`, whose two classes are both
 * pre-processual and neither is a filing class. It falls back to the whole list
 * rather than returning nothing, because an empty candidate set is an empty
 * `enum`, and an empty enum is an invalid grammar.
 */
final class ProceduralClassCandidatesQuery
{
    /**
     * @return Collection<int, ProceduralClass>
     */
    public function forArea(PracticeArea $area): Collection
    {
        // Through the relation, not a fresh query on the model: it is what
        // carries the pivot `scope` and orders the area's own classes ahead of
        // the civil trunk it merely borrows.
        $filing = $area->proceduralClasses()
            ->where('procedural_classes.is_filing_class', true)
            ->get();

        return $filing->isEmpty()
            ? $area->proceduralClasses()->get()
            : $filing;
    }
}
