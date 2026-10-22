<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The pleading's second identifier: six letters and digits a person can read
 * out, where the uuid is what the routes and the foreign keys go on using.
 *
 * Case counts — `aB3x9Q` and `AB3X9Q` are two pleadings —, which the default
 * Postgres collation already gives the unique index for free. The index is
 * global and not per account: a code that names one pleading in the whole
 * system is what the platform staff can be told over the phone.
 *
 * The column is added nullable, every existing row is given a code, and only
 * then is it made NOT NULL. The query builder rather than the model, so that
 * soft-deleted rows get one too — restoring a row without a code would break
 * the constraint — and so that this file does not lean on the model's draw,
 * which will still change. The draw here is checked against an in-memory set:
 * before this migration no row has a code, so that set is all there is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_cases', function (Blueprint $table): void {
            $table->char('code', 6)->nullable();
        });

        $this->backfill();

        Schema::table('legal_cases', function (Blueprint $table): void {
            $table->char('code', 6)->nullable(false)->change();
            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::table('legal_cases', function (Blueprint $table): void {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }

    private function backfill(): void
    {
        $taken = [];

        foreach (DB::table('legal_cases')->select('id')->lazyById() as $row) {
            do {
                $code = Str::random(6);
            } while (isset($taken[$code]));

            $taken[$code] = true;

            DB::table('legal_cases')->where('id', $row->id)->update(['code' => $code]);
        }
    }
};
