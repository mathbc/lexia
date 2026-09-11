<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts are the tenant boundary: every other domain row hangs off one.
 *
 * This runs before the users table, which holds the foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('type', 20);

            // Individual accounts are identified by their OAB enrolment,
            // law firms by their CNPJ. Exactly one side is filled in.
            $table->string('federal_id', 14)->nullable();
            $table->string('oab_number', 20)->nullable();
            $table->char('oab_state', 2)->nullable();

            $table->string('email');
            $table->string('phone', 20);

            $table->char('postal_code', 8);
            $table->string('street');
            $table->string('number', 20);
            $table->string('complement', 100)->nullable();
            $table->string('district', 120);
            $table->string('city', 120);
            $table->char('state', 2);

            // `enabled` is LexIA's switch (contract, billing); `active` is the
            // customer's own. The account only operates when both are true.
            $table->boolean('active')->default(true);
            $table->boolean('enabled')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['active', 'enabled']);
            $table->index('type');
        });

        // Partial unique indexes: uniqueness must hold among the rows that
        // actually carry the identifier, and must not collide across the many
        // NULLs of the other account type. Also excludes soft-deleted rows so
        // a CNPJ can be re-registered after its account is deleted.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX accounts_federal_id_unique
                ON accounts (federal_id)
                WHERE federal_id IS NOT NULL AND deleted_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX accounts_oab_unique
                ON accounts (oab_number, oab_state)
                WHERE oab_number IS NOT NULL AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
