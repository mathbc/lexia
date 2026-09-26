<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a theme selection run is remembered — the marker of the sixth step's
 * second tab.
 *
 * The third of its kind, after `research_findings` and
 * `court_decision_findings`, and the reason it could not share the first one is
 * the whole design of the step. The theses and the themes are researched in
 * parallel but **marked apart**: a pleading researched before the themes tab
 * existed has theses and no themes, and opening the step must run only the
 * missing half. Keyed on one shared column, it would run both — and the theses
 * half reconciles by diff, so a second run silently replaces what the lawyer
 * already curated. The same goes for a half that failed while the other one
 * landed, and for "Pesquisar novamente" pressed on one tab.
 *
 * Null means never selected, and it is what makes the step ask. Present means
 * a run happened, including the one that found no theme that applies — which
 * writes zero links, so a trigger keyed on the links would fire on every visit.
 *
 * jsonb for the reason the siblings give: its shape belongs to
 * `LegalThemeResearchData::findings()`, one screen reads it whole, and nothing
 * queries it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_cases', function (Blueprint $table): void {
            $table->jsonb('theme_findings')->nullable()->after('court_decision_findings');
        });
    }

    public function down(): void
    {
        Schema::table('legal_cases', function (Blueprint $table): void {
            $table->dropColumn('theme_findings');
        });
    }
};
