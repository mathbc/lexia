<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where each court's instance of a system answers: the address the lawyer
 * opens to file the pleading.
 *
 * On the link and not on the system, because there is no system-wide address
 * to open. The eproc of Santa Catarina and the eproc of Rio Grande do Sul are
 * the same software on two hosts, each court's own, and so are the PJe and the
 * e-SAJ; a "link do eproc" would take the lawyer nowhere they can file.
 *
 * Nullable because the map may record a court moving to a system before its
 * instance is public, and then there is nothing to link to. The rows arrive in
 * the reload that follows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('judicial_system_courts', function (Blueprint $table): void {
            $table->string('url', 255)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('judicial_system_courts', function (Blueprint $table): void {
            $table->dropColumn('url');
        });
    }
};
