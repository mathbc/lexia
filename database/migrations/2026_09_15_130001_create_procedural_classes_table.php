<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The CNJ procedural classes — how a case is docketed.
 *
 * Reference data like practice areas, and for the same reason unscoped by
 * account.
 *
 * The pivot below is the point of the whole design: one class serves many
 * areas. "Procedimento Comum Cível" (code 7) is filed in civil, consumer,
 * property, family and tax matters alike, so a practice_area_id column here
 * would duplicate the row five times over and cost the CNJ code its meaning as
 * a unique natural key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procedural_classes', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // The CNJ's own identifier: the number that travels in DataJud and
            // PJe integrations and appears on the case record. Unique rather
            // than the primary key, so the foreign keys stay uuid throughout.
            $table->integer('code')->unique();

            $table->string('name');
            $table->string('slug');

            // One of the ten roots of the CNJ classes table, and the full path
            // down to this node — kept denormalised because the intermediate
            // grouping nodes are not imported: they are not selectable.
            $table->integer('root_code');
            $table->jsonb('path');

            $table->string('abbreviation', 40)->nullable();

            // Left as a free string on purpose: the CNJ returns 41 distinct
            // values for what ought to be a closed set, typos ("Recursaç") and
            // casing variants included. The two flags below are what the
            // product actually branches on, and they arrive pre-computed.
            $table->string('nature', 60)->nullable();

            $table->string('legal_norm')->nullable();
            $table->string('legal_article')->nullable();
            $table->string('active_party', 80)->nullable();
            $table->string('passive_party', 80)->nullable();

            $table->boolean('has_own_numbering')->default(true);

            // Opens a new case: what a petição inicial may be filed under.
            $table->boolean('is_filing_class')->default(false);

            // Incidents, appeals, letters rogatory, enforcement of judgment.
            // Never offered on a new-case screen.
            $table->boolean('is_cross_cutting')->default(false);

            // The 29 CNJ competences (branch of justice × level). A labour
            // lawyer has no use for the small claims court of the treasury, so
            // the listing filters on this before it filters on anything else.
            $table->jsonb('jurisdictions');

            $table->timestamps();

            $table->index('root_code');
            $table->index('is_filing_class');
        });

        // GIN because the filter is containment ("which classes apply in
        // just_es_1grau?"), which a btree cannot answer.
        DB::statement(<<<'SQL'
            CREATE INDEX procedural_classes_jurisdictions_idx
                ON procedural_classes USING GIN (jurisdictions)
        SQL);

        Schema::create('practice_area_procedural_class', function (Blueprint $table): void {
            $table->foreignUuid('practice_area_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('procedural_class_id')->constrained()->cascadeOnDelete();

            // 'specific' — a class that belongs to this area.
            // 'generic'  — a civil trunk class that serves any civil area.
            // Sorting specific first is the difference between a usable select
            // and one with 120 items in no particular order.
            $table->string('scope', 10)->default('specific');

            $table->primary(['practice_area_id', 'procedural_class_id']);

            // The composite primary key already covers lookups from the area;
            // this one covers the other direction.
            $table->index('procedural_class_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_area_procedural_class');
        Schema::dropIfExists('procedural_classes');
    }
};
