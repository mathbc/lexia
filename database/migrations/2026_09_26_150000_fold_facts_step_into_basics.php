<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The narrative and the urgency no longer have a step of their own.
 *
 * They moved into the first step, and `LegalCaseStep` lost its `facts` case
 * with them. A pleading whose high-water mark still reads `facts` would now
 * break the enum cast the moment it is loaded, so every such row is moved on.
 *
 * To `requirements`, and not back to `defendant`: the mark names the next step
 * owed, and a pleading that stood on the facts had already saved the basics and
 * the defendant. The facts themselves live in the first step now, open to a
 * pleading at any mark — nothing is locked by moving forward.
 *
 * The query builder rather than the model, so that soft-deleted rows are moved
 * too: restoring one later would hit the same cast.
 *
 * `down()` is empty on purpose. Once moved, a row that stood on the facts
 * cannot be told apart from one that reached the requests on its own, and
 * rewinding every `requirements` would be the larger error.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('legal_cases')
            ->where('current_step', 'facts')
            ->update(['current_step' => 'requirements']);
    }

    public function down(): void
    {
        //
    }
};
