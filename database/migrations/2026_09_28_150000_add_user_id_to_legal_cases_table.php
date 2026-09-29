<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who opened the pleading — the user the dashboard filters by.
 *
 * Until now the author existed only as a runtime argument (the lawyer who signs
 * the minuta) and was never stored, so there was nothing to count per person.
 * `CreateLegalCase` writes it from here on.
 *
 * Nullable, and deliberately left null for the rows that already exist: a
 * backfill would have to guess, and a pleading attributed to the wrong lawyer
 * is worse than one attributed to nobody. Those rows still count under "Todos
 * os usuários"; they just never answer to a single name.
 *
 * `nullOnDelete` rather than cascade: users are soft-deleted, so the constraint
 * only fires on a hard delete, and the pleading belongs to the account, not to
 * the person who happened to start it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_cases', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->nullable()->after('account_id')->constrained()->nullOnDelete();

            // The dashboard always starts from the account, like every other
            // query on this table, and narrows to the user second.
            $table->index(['account_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('legal_cases', function (Blueprint $table): void {
            $table->dropIndex(['account_id', 'user_id']);
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
