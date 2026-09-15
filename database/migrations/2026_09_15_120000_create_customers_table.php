<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The clients an account handles matters for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Cascade, unlike users: a client has no existence outside the
            // account that registered them, so it cannot outlive it.
            $table->foreignUuid('account_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('type', 20);

            // A natural person is identified by their CPF, a company by its
            // CNPJ. Exactly one side is filled in, which is why neither can be
            // NOT NULL and why the unique indexes below are partial.
            $table->char('cpf', 11)->nullable();
            $table->char('cnpj', 14)->nullable();

            $table->string('email');
            $table->string('phone', 20);

            $table->char('postal_code', 8);
            $table->string('street');
            $table->string('number', 20);
            $table->string('complement', 100)->nullable();
            $table->string('district', 120);
            $table->string('city', 120);
            $table->char('state', 2);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['account_id', 'name']);
            $table->index(['account_id', 'type']);
        });

        // Uniqueness holds inside the account, not across the platform: two
        // firms may have the same person as a client. Partial so the many
        // NULLs of the other type never collide, and so a document can be
        // registered again after its client is deleted.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX customers_account_cpf_unique
                ON customers (account_id, cpf)
                WHERE cpf IS NOT NULL AND deleted_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX customers_account_cnpj_unique
                ON customers (account_id, cnpj)
                WHERE cnpj IS NOT NULL AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
