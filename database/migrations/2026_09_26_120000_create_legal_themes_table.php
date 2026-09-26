<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The STJ's qualified precedents, as its open-data portal publishes them.
 *
 * A *tema* is a legal question the court singled out to settle once, because
 * thousands of cases were asking it; after the thesis is fixed, every judge
 * facing the same question must observe it (CPC, arts. 927 and 1.040). The
 * file also carries the controversies that may become a tema, PUILs, IACs and
 * SIRDRs, and `type` is what tells them apart.
 *
 * Reference data like `procedural_classes`: global, no `account_id`, no soft
 * deletes. The columns are the CSV one to one, under English names; the
 * mapping lives in LegalThemeRecord, which is the only place that knows the
 * STJ's headers.
 *
 * `sequential_number` is the STJ's own id for the precedent and the key an
 * import upserts on. It is unique here and **not** in the file: the CSV repeats
 * a precedent's row once per STF general repercussion it is linked to, with
 * only those two columns changing. Those links are `general_repercussions`.
 *
 * `status` stays raw text. The file spells 22 situations, "Cancelada" and
 * "Cancelado" among them, and a new one appears whenever the STJ decides; an
 * enum would turn that into a failed import for a column nobody reasons about.
 *
 * No ANN index, for the reason the procedural-class vector gives: ~2.4k rows
 * are an exact scan measured in milliseconds, and exact is never quietly wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::getConnection()->getSchemaBuilder()->ensureVectorExtensionExists();

        Schema::create('legal_themes', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->integer('sequential_number')->unique();
            $table->string('type', 20);
            $table->integer('number');

            $table->date('first_assigned_on')->nullable();
            $table->date('judged_on')->nullable();
            $table->date('judgment_published_on')->nullable();

            $table->string('status', 80)->index();

            $table->text('additional_information')->nullable();
            $table->text('question');
            $table->text('settled_thesis')->nullable();
            $table->text('nugepnac_notes')->nullable();
            $table->text('judgment_scope')->nullable();
            $table->text('previous_understanding')->nullable();
            $table->text('legislative_reference')->nullable();

            $table->integer('referenced_sumula')->nullable();
            $table->integer('originated_sumula')->nullable();

            // Three states on purpose: the file writes S, N, or nothing.
            $table->boolean('public_hearing')->nullable();
            $table->date('public_hearing_on')->nullable();

            $table->string('judging_body', 20)->nullable();

            // `[{code, name}]` — the CNJ subject codes the STJ prefixes to
            // each name ("1156- DIREITO DO CONSUMIDOR").
            $table->jsonb('subjects');

            $table->vector('embedding', 768)->nullable();
            $table->string('embedding_hash', 64)->nullable();
            $table->timestamp('embedded_at')->nullable();

            $table->timestamps();

            // "Tema 1016" names exactly one precedent — measured, and what a
            // citation in a pleading will be looked up by.
            $table->unique(['type', 'number']);
        });

        Schema::create('general_repercussions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('legal_theme_id')->constrained()->cascadeOnDelete();

            // The STF's tema number and its description, copied as the file
            // writes them. The same repercussion links to several precedents,
            // so the description repeats across rows — accepted over a
            // many-to-many that would model the STF catalogue from a file that
            // only lists it in passing.
            $table->integer('number');
            $table->text('description');

            $table->timestamps();

            $table->unique(['legal_theme_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('general_repercussions');
        Schema::dropIfExists('legal_themes');
    }
};
