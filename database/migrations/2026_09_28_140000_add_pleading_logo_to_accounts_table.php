<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The logo printed in the pleading's letterhead.
 *
 * A path on the private disk, like the two interface logos beside it — see
 * `add_logos_to_accounts_table` for why a file and not Base64. Nullable and
 * independent of them: without it the letterhead is drawn with text only, and
 * it never falls back to the sidebar's, which is cut for a 32 px square.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('logo_pleading_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn('logo_pleading_path');
        });
    }
};
