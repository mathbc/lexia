<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the addressing chain's suggestion is remembered, beside the two fields
 * it pre-filled.
 *
 * `court_addressing` and `judicial_system_id` stay what they were: the line the
 * petição opens with and the system it is filed through, both the lawyer's to
 * keep or change. This column is their **provenance** — which court, which
 * forum, from where the city came, why, and through which system —, written
 * when the first step is saved. It is what lets the screen go on saying
 * "Sugestão da IA" after a reload, and "editada" once the text no longer
 * matches the one suggested.
 *
 * Null means no suggestion was ever asked for — a pleading built by hand, or a
 * smart fill whose step failed —, and the screen offers "Consultar IA".
 *
 * jsonb for the reason `injunctive_relief_suggestion` gives: its shape belongs
 * to `CourtAddressingSuggestionData::toArray()`, one screen reads it whole, and
 * nothing queries it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_cases', function (Blueprint $table): void {
            $table->jsonb('court_addressing_suggestion')->nullable()->after('court_addressing');
        });
    }

    public function down(): void
    {
        Schema::table('legal_cases', function (Blueprint $table): void {
            $table->dropColumn('court_addressing_suggestion');
        });
    }
};
