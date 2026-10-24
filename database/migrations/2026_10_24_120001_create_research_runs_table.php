<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One research of a saved pleading, per subject, while it runs in the queue.
 *
 * The fifth step's three tabs and the sixth step each research in the
 * `inference` queue — see RunLegalCaseForensicReview and
 * RunLegalCaseJurisprudence —, so the screen needs to know, across a reload or
 * another browser tab, that a subject is being researched and whether the last
 * attempt failed. This is that state, and only that: the markers on
 * `legal_cases` (`research_findings`, `theme_findings`, `doctrine_findings`,
 * `court_decision_findings`) remain the one signal that a subject was ever
 * researched.
 *
 * **One row per run**, not one per subject rewritten in place: the job is
 * handed the ids of the rows it was queued for, so a run the screen already
 * gave up on can never be revived by a worker that picks its job up late.
 *
 * The partial unique index is the guarantee behind "one run in flight per
 * subject". The Action that queues locks the pleading first, so the index is
 * the net and not the mechanism — a double click, or two browser tabs, would
 * otherwise queue the same paid research twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('legal_case_id')->constrained()->cascadeOnDelete();

            $table->string('subject', 20);
            $table->string('status', 20);
            $table->text('failure')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['legal_case_id', 'subject', 'created_at']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX research_runs_single_in_flight
                ON research_runs (legal_case_id, subject)
                WHERE status IN ('queued', 'running')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('research_runs');
    }
};
