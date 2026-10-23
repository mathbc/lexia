<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The provider's API key moves from the `.env` to the model row.
 *
 * One per row, and not one per provider: the text and the embedding catalogues
 * are kept apart, and nothing stops a Gemini text model and a Gemini embedding
 * model from being billed to different projects. Encrypted by the model's
 * `encrypted` cast, so the column holds the APP_KEY ciphertext — `text`, because
 * that payload is several times the key's length.
 *
 * Nullable: Ollama's local daemon asks for none, and a Gemini row registered
 * before this migration stays without one until staff type it in
 * /modelos-de-ia or `lexia:install --gemini-key` fills it. No row is backfilled
 * here — a migration cannot read the `.env` it is retiring without reading it
 * in a way that silently fails once the config is cached.
 */
return new class extends Migration
{
    private const array TABLES = ['ai_models', 'embedding_models'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->text('api_key')->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('api_key');
            });
        }
    }
};
