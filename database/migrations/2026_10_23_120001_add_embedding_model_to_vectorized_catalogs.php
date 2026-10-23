<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which model made each vector.
 *
 * The embedding model became a choice, and a vector from one model is not
 * comparable with a vector from another: both are 768 numbers, the distance
 * between them is computed without complaint, and it means nothing. So each
 * row says which model it came from — `EmbeddingModel::key()`, as in
 * `gemini:gemini-embedding-001` —, the retrieval only compares rows made by the
 * current default, and a row made by any other one counts as stale.
 *
 * The vectors already stored were all made by the local nomic, the only model
 * the project had, and they are stamped with it rather than discarded: on a
 * database that keeps nomic as its default nothing has to be embedded again.
 */
return new class extends Migration
{
    private const array TABLES = ['procedural_classes', 'legal_themes'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('embedding_model', 150)->nullable()->after('embedding_hash');
            });

            DB::table($name)
                ->whereNotNull('embedding')
                ->update(['embedding_model' => 'ollama:nomic-embed-text']);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('embedding_model');
            });
        }
    }
};
