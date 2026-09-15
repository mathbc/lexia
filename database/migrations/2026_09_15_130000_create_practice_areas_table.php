<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The practice areas a lawyer picks from before anything else in a pleading.
 *
 * Reference data shared by every account, so there is no account_id here:
 * Direito Civil is the same for all of them.
 *
 * The taxonomy is ours, not the CNJ's. The CNJ organises procedural classes by
 * the nature of the proceeding, never by branch of law — Direito Imobiliário
 * does not exist there at all. cnj_subject_roots keeps the bridge back to the
 * table where the CNJ *does* classify by area, the subjects one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practice_areas', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // What survives a resync: the uuid is local to each database, the
            // slug is what the dataset, the URLs and the fixtures speak.
            $table->string('slug', 60)->unique();
            $table->string('label', 120);

            $table->jsonb('cnj_subject_roots');

            // Curated order, not alphabetical: the areas a firm works in most
            // come first in the select.
            $table->smallInteger('position')->default(0);

            $table->timestamps();

            $table->index('position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_areas');
    }
};
