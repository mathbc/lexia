<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the pleading asks the court for — the numbered list that closes it.
 *
 * "Ante o exposto, requer: a concessão da gratuidade da justiça; a citação do
 * Réu; a procedência total dos pedidos…". A pleading carries many of them, the
 * lawyer writes and rewrites them one at a time, and each is a sentence rather
 * than a field — the same shape as `documents`, and for the same reason: a
 * JSON column would be neither indexable nor joinable.
 *
 * `description` is the request itself and is required, because a request with
 * no text is not a request. `amount` is the money it claims — the fifty
 * thousand of a damages claim, the limit of an asset freeze — and is nullable,
 * because most requests claim none: waiving court fees, serving the defendant
 * and awarding fees at a percentage are all worth nothing in reais.
 *
 * There is no `position` column yet. The order is the order the rows were
 * written in, which is what the drafting form produces; when reordering
 * becomes a gesture the screen offers, it arrives with the Action that saves
 * the pleading, and not before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requirements', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Cascade, like the documents: a request has no existence outside
            // the account that drafted it, nor outside the pleading it closes.
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('legal_case_id')->constrained()->cascadeOnDelete();

            // Long enough for a request that cites an article and its
            // conditions — the tutela de urgência is routinely a paragraph.
            $table->text('description');

            // Money, so decimal rather than float: a claim of R$ 50.000,00 is
            // a figure that goes into a judgment, and the binary rounding of a
            // float has no business anywhere near it. Thirteen digits before
            // the comma is more than any claim needs.
            $table->decimal('amount', 15, 2)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Every listing starts from the account, then narrows to one
            // pleading — the same shape as the index on `documents`.
            $table->index(['account_id', 'legal_case_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requirements');
    }
};
