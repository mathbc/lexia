<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a theme sits in the pleading's ranking — 0 is the most relevant.
 *
 * The links were a set while the selection was a filter: it kept the themes
 * that applied, and the screen ordered them by kind and number. Now that it
 * ranks — it always returns a few themes, most relevant first, for the lawyer
 * to untick — the order is the finding, and a set would throw it away on the
 * way to the table. `LegalCaseThemeListData::toSync()` writes each link's index
 * and `LegalCase::themes()` reads it back in that order.
 *
 * Zero by default and not nullable: a link written before the column existed
 * has no rank to recover, and a tie at zero falls back to the insertion order,
 * which is the order the old selection returned them in anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_case_themes', function (Blueprint $table): void {
            $table->unsignedSmallInteger('position')->default(0)->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('legal_case_themes', function (Blueprint $table): void {
            $table->dropColumn('position');
        });
    }
};
