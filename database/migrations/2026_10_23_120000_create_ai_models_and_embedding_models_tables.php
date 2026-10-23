<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The models the platform can run: one table for text, one for embeddings.
 *
 * Reference data shared by every account, like the judicial systems, so no
 * account_id and no soft delete. Two tables rather than a `kind` column because
 * each has its own default, and the embedding one carries consequences the text
 * one does not — the vectors already stored were made by it.
 *
 * `default` is a reserved word in Postgres. The schema and query builders quote
 * it; raw SQL, like the indexes below, has to quote it by hand.
 *
 * No row arrives here: `php artisan lexia:install` registers the Gemini pair,
 * because a migration that inserts a model would also decide which one is the
 * default on every environment that runs it.
 */
return new class extends Migration
{
    private const array TABLES = ['ai_models', 'embedding_models'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->uuid('id')->primary();

                // AiProvider: the instance name under config('ai.providers').
                $table->string('provider', 20);

                // The model id the provider's API speaks — `gemini-3.7-flash`,
                // `gpt-oss:20b` —, sent as is.
                $table->string('name', 120);

                $table->boolean('default')->default(false);

                $table->timestamps();

                $table->unique(['provider', 'name']);
            });

            // At most one default per table, held by the database: a partial
            // unique index over the rows that are, so two can never be.
            DB::statement("CREATE UNIQUE INDEX {$name}_single_default ON {$name} (\"default\") WHERE \"default\"");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::dropIfExists($name);
        }
    }
};
