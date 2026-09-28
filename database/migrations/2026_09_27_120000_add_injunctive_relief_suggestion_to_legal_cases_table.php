<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the urgent-relief agent's suggestion is remembered, beside the decision
 * it pre-filled.
 *
 * `injunctive_relief` and its description stay what they were: the lawyer's
 * decision and the text the petição argues from. This column is the
 * **provenance** of that text — what the agent said, of which species, why, and
 * when —, written when the first step is saved. It is what lets the screen go
 * on saying "Sugestão da IA" after a reload, and "editada" once the text in the
 * box no longer matches the one suggested.
 *
 * Null means no suggestion was ever asked for — a pleading built by hand, or
 * one whose smart fill failed at this step —, and the screen offers "Consultar
 * IA". Present with `recommended` false is the opposite statement: the agent
 * read the story and saw no urgency, which is an answer.
 *
 * jsonb for the reason the findings columns give: its shape belongs to
 * `InjunctiveReliefSuggestionData::toArray()`, one screen reads it whole, and
 * nothing queries it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_cases', function (Blueprint $table): void {
            $table->jsonb('injunctive_relief_suggestion')->nullable()->after('injunctive_relief_description');
        });
    }

    public function down(): void
    {
        Schema::table('legal_cases', function (Blueprint $table): void {
            $table->dropColumn('injunctive_relief_suggestion');
        });
    }
};
