<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an agent that fell is written down.
 *
 * `report()` already sends every failure to the log, and it still does. What the
 * log cannot answer is the question operation asks — which agent, against which
 * model, how often, and what the provider said back —, because the line it keeps
 * is a stack trace and the cause is three `previous` deep. This table keeps the
 * answer flat, one row per failure, written by `RecordAgentFailure`.
 *
 * `message` is what came back from the other side: the `error.message` of the
 * provider's body, or what the model answered when the Action refused it. It is
 * nullable because a timeout has no other side — the cURL gave up before anyone
 * answered, and `http_status` is null for the same reason.
 *
 * `agent` is the short class name of the agent that was running
 * (`LegalThesisResearchAgent`), or of the Action that held the stage when no
 * agent can be named — the child killed by its clock has no stack to read.
 *
 * Append-only, with no soft delete and no `updated_at`: a failure is a fact that
 * happened once. The account and the pleading are nullable and set null on
 * delete, because a row about the past should outlive what it was about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents_execution_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('legal_case_id')->nullable()->constrained()->nullOnDelete();

            $table->string('agent');
            $table->string('operation');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('message')->nullable();
            $table->text('error');
            $table->string('model')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['agent', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents_execution_logs');
    }
};
