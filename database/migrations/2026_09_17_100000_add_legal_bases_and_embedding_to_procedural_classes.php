<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns the classification agent needs, and one Postgres extension.
 *
 * `legal_bases` is the fundamentação a peça cita — CPC art. 319, CC arts. 186
 * e 927, CPC art. 300 — and it is plural on purpose. The CNJ gives one norm
 * and one article range per class (`legal_norm`/`legal_article`, kept as they
 * arrive from the SGT web service), which says where the *class* is defined
 * and nothing about what a pleading under it invokes. Editorial like
 * `description`, so a resync with the CNJ must preserve it by `code`.
 *
 * `embedding` is what makes the enrichment affordable. The descriptions grew
 * to carry the deadline, the trigger and the instruments, and the worst area —
 * penal, 45 candidates — would now spend ~30 KB of a 16k-token window on the
 * candidate list alone. With the vector, only the classes closest to the facts
 * arrive described; the rest arrive as a name. See ProceduralClassRanking.
 *
 * No ANN index, deliberately. pgvector's HNSW trades recall for speed on large
 * corpora; at 615 rows × 768 dimensions the exact scan is under two megabytes
 * and sub-millisecond, and exact means the ranking is never quietly wrong. The
 * index becomes worth it when Jurisprudência arrives with its own table.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent, and Postgres-only — which this project is.
        Schema::getConnection()->getSchemaBuilder()->ensureVectorExtensionExists();

        Schema::table('procedural_classes', function (Blueprint $table): void {
            // Nullable rather than defaulted: `[]` and "não escrevemos ainda"
            // are different things, and the loader distinguishes them.
            $table->jsonb('legal_bases')->nullable()->after('legal_article');

            $table->vector('embedding', 768)->nullable()->after('jurisdictions');

            // sha256 of the exact text that produced the vector above. It is
            // what lets the embedding step skip a row whose description,
            // subjects and bases have not moved — a catalogue resync re-embeds
            // only what actually changed.
            $table->string('embedding_hash', 64)->nullable()->after('embedding');

            $table->timestamp('embedded_at')->nullable()->after('embedding_hash');
        });
    }

    public function down(): void
    {
        Schema::table('procedural_classes', function (Blueprint $table): void {
            $table->dropColumn(['legal_bases', 'embedding', 'embedding_hash', 'embedded_at']);
        });

        // The extension is left in place: dropping it would take any other
        // vector column in the database with it.
        DB::statement('SELECT 1');
    }
};
