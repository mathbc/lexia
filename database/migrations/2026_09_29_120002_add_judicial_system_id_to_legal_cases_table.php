<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The electronic system the pleading will be filed through — eproc, PJe,
 * e-SAJ —, picked in the first step next to the client.
 *
 * Nullable, and optional on the form: the lawyer may not know yet, a case for
 * the federal or labour courts sits outside the state courts the map covers,
 * and the rows that already exist are left null rather than guessed.
 *
 * Restrict on delete, like the practice area and the procedural class: the
 * system is reference data, and one still cited by a pleading must not vanish.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_cases', function (Blueprint $table): void {
            $table->foreignUuid('judicial_system_id')->nullable()->after('procedural_class_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('legal_cases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('judicial_system_id');
        });
    }
};
