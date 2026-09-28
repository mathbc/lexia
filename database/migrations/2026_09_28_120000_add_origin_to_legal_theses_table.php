<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who wrote each thesis — `ai` for the research, `manual` for the lawyer.
 *
 * The default is the backfill: until now the only way a thesis reached the table
 * was the research, so every existing row is `ai`, and new rows keep being `ai`
 * unless CreateLegalThesis says otherwise. See LegalThesisOrigin for what the
 * distinction buys.
 *
 * A string and not a boolean because the value is read by a label, and because
 * a third author — a thesis imported from another pleading, say — is a new case
 * rather than a new column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_theses', function (Blueprint $table): void {
            $table->string('origin', 20)->default('ai');
        });
    }

    public function down(): void
    {
        Schema::table('legal_theses', function (Blueprint $table): void {
            $table->dropColumn('origin');
        });
    }
};
