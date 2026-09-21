<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a thesis research run is remembered.
 *
 * The theses and the precedents have had tables since the forensic review was
 * built. What had nowhere to live is everything around them: the question that
 * was researched, the official pages that were opened, what stayed unresolved,
 * and the citations `OfficialLegalSources::covers()` refused. That last one is
 * the reason this column is not a nicety — a lawyer looking at a step with no
 * theses needs to tell "nothing was found" apart from "the guard threw it out",
 * and only this tells them which.
 *
 * It is jsonb and not four columns because nothing queries it: it is read whole
 * by one screen, written whole by one Action, and its shape belongs to
 * `LegalResearchData::findings()` rather than to the schema.
 *
 * **It is also the marker that the research already ran**, and that is the load
 * bearing half. `ResearchLegalCaseForensicReview` fires once, when step 6 opens
 * on a pleading that has never been researched, and a run that confirms nothing
 * writes zero theses — so a trigger keyed on `theses` being empty would fire
 * again on every visit, spending the Gemini quota each time and, worse,
 * silently replacing what the lawyer had already curated, because
 * `SaveLegalCaseForensicReview` diffs with `whereNotIn(...)->delete()`. Keyed on
 * this column being null, it fires exactly once. The second run is the lawyer's
 * to ask for, with the button that says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_cases', function (Blueprint $table): void {
            $table->jsonb('research_findings')->nullable()->after('is_draft');
        });
    }

    public function down(): void
    {
        Schema::table('legal_cases', function (Blueprint $table): void {
            $table->dropColumn('research_findings');
        });
    }
};
