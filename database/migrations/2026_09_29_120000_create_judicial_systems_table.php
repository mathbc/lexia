<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The electronic systems pleadings are filed through, and which state court
 * runs which.
 *
 * Reference data shared by every account, so there is no account_id here, and
 * two tables rather than one because the relation is many-to-many with
 * something to say on the link: the eproc serves fourteen courts, São Paulo
 * runs both the e-SAJ and the eproc, and the Bahia court is only in transition
 * to it. The rows arrive in the migration that follows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('judicial_systems', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // What survives a resync, as in practice_areas: the uuid is local
            // to each database, the slug is what the data file speaks.
            $table->string('slug', 30)->unique();
            $table->string('name', 60);

            // By reach, not alphabetical: the systems most courts use come
            // first in the select.
            $table->smallInteger('position')->default(0);

            $table->timestamps();

            $table->index('position');
        });

        Schema::create('judicial_system_courts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('judicial_system_id')->constrained()->cascadeOnDelete();

            // One court of justice per state, so the state identifies it; the
            // acronym is kept beside it because the DF's is the TJDFT, which no
            // rule on the state would produce.
            $table->char('state', 2);
            $table->string('court', 10);

            // AdoptionStatus: in use, in transition, being rolled out, or
            // coexisting with the systems it is replacing.
            $table->string('status', 20);

            $table->timestamps();

            $table->unique(['judicial_system_id', 'state']);
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('judicial_system_courts');
        Schema::dropIfExists('judicial_systems');
    }
};
