<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a petição inicial needs to qualify a natural person.
 *
 * Until here the opening paragraph of a pleading had no marital status and no
 * occupation to copy, and PleadingDraftingAgent was told to write a bracket
 * rather than guess — "brasileiro, casado, comerciante" is grammatically
 * obligatory, banal, and wrong about a real person. These three columns are
 * where that stops being a gap in the schema.
 *
 * All nullable, and for two separate reasons. A company has no civil status,
 * so the columns are empty for every Pessoa Jurídica by construction — the
 * same partial shape `cpf` already has. And a client registered before this
 * migration, or registered in a hurry, simply does not have them yet: NULL is
 * "ninguém informou", and the draft falls back to the bracket for that one
 * field instead of the registration refusing to be saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            // The enum's backed value, not the label: identifiers stay in
            // English and the Portuguese lives in MaritalStatus::label().
            $table->string('marital_status', 20)->nullable()->after('cnpj');
            $table->string('occupation', 120)->nullable()->after('marital_status');
            $table->date('birth_date')->nullable()->after('occupation');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['marital_status', 'occupation', 'birth_date']);
        });
    }
};
