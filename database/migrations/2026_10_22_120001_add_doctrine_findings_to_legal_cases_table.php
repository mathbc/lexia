<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a doctrine run is remembered — the marker of the fifth step's third tab.
 *
 * The twin of `theme_findings` and `court_decision_findings`, for the same two
 * reasons. It holds what has no table — the search terms the agent condensed
 * the case into, the records it opened, what stayed unresolved and the works the
 * guard refused — and, the load-bearing half, **it is the marker that the run
 * happened**. A run that confirms no work writes zero rows, so a trigger keyed on
 * `doctrines` being empty would research again on every visit, spending Gemini
 * quota and silently replacing what the lawyer had already curated. Keyed on
 * this column being null, it fires once, and a second run is "Pesquisar
 * novamente".
 *
 * jsonb, read whole by one screen and written whole by one Action; its shape
 * belongs to `DoctrineResearchData::findings()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_cases', function (Blueprint $table): void {
            $table->jsonb('doctrine_findings')->nullable()->after('theme_findings');
        });
    }

    public function down(): void
    {
        Schema::table('legal_cases', function (Blueprint $table): void {
            $table->dropColumn('doctrine_findings');
        });
    }
};
