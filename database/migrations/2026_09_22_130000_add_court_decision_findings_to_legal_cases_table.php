<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a jurisprudence run is remembered — the seventh step's marker.
 *
 * The twin of `research_findings`, one step later, and it exists for the same
 * two reasons in the same order.
 *
 * The first is what has no table. `court_decisions` holds the rulings; it does
 * not hold the *account of the run* — the question that was asked, the LexML
 * records that were actually opened, what stayed unresolved, and the citations
 * `CourtDecisionSources::isRecord()` refused. That last one is not a nicety: a
 * lawyer looking at a step with no rulings needs to tell "the courts have not
 * decided this" apart from "the guard threw out what the agent found", and the
 * two produce exactly the same empty list.
 *
 * The second is the load-bearing half: **it is the marker that the run
 * happened**. `ResearchLegalCaseJurisprudence` fires once, when step 7 opens on
 * a pleading that has never been researched, and a run that confirms nothing
 * writes zero rows — so a trigger keyed on `court_decisions` being empty would
 * fire again on every visit, spending Gemini quota each time and, worse,
 * silently replacing what the lawyer had already curated, since
 * `SaveLegalCaseCourtDecisions` diffs with `whereNotIn(...)->delete()`. Keyed on
 * this column being null it fires exactly once, and the second run is the
 * lawyer's to ask for.
 *
 * jsonb and not five columns for the reason the sibling gives: nothing queries
 * it. It is read whole by one screen, written whole by one Action, and its
 * shape belongs to `CourtDecisionResearchData::findings()` rather than to the
 * schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_cases', function (Blueprint $table): void {
            $table->jsonb('court_decision_findings')->nullable()->after('research_findings');
        });
    }

    public function down(): void
    {
        Schema::table('legal_cases', function (Blueprint $table): void {
            $table->dropColumn('court_decision_findings');
        });
    }
};
