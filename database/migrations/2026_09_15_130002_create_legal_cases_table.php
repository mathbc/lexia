<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The pleadings an account drafts for its clients.
 *
 * Only the skeleton for now: who it belongs to, for whom, and where in the CNJ
 * taxonomy it sits. The drafted text, the subject and the pleading type come
 * once the assembly flow is settled.
 *
 * Nothing here stops a class from being paired with an area it does not belong
 * to — that pairing lives in the pivot, and enforcing it in the database would
 * mean a composite foreign key into it. It belongs in the Action's validation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Cascade, like customers: a pleading has no existence outside the
            // account that drafted it, nor outside the client it is for.
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();

            // Restrict, unlike the two above: the catalogue is reference data,
            // and an area still cited by a pleading must not vanish under it.
            $table->foreignUuid('practice_area_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('procedural_class_id')->constrained()->restrictOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['account_id', 'customer_id']);
            $table->index(['account_id', 'practice_area_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_cases');
    }
};
