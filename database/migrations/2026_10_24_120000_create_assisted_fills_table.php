<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One smart fill, from the moment it is asked for to the pleading it saved.
 *
 * The smart fill runs in the `inference` queue — see RunAssistedFill —, so the
 * request that asks for it answers before any agent is called and the screen
 * needs something to wait on. This row is it: the screen polls its status, and
 * the worker writes the outcome here.
 *
 * `facts` is the narrative the worker reads. It stays while the row may still
 * be needed to put the form back — a refusal, a failure, a framing without a
 * class — and is cleared once the pleading is saved, because from then on the
 * pleading is where the narrative lives.
 *
 * `result` is `LegalCaseClassification::toArray()` plus the pleading's id: the
 * payload the screen used to receive as JSON, kept for the one path that still
 * needs it — no class, no pleading, and the hand-off to `/pecas/nova`.
 *
 * `status` is text and not a database enum, as every other enum column here.
 * The account and the user are explicit, never read from `auth()`: the worker
 * that writes the outcome has no session.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assisted_fills', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('legal_case_id')->nullable()->constrained()->nullOnDelete();

            $table->text('facts')->nullable();
            $table->string('status', 20);
            $table->jsonb('result')->nullable();
            $table->text('failure')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assisted_fills');
    }
};
