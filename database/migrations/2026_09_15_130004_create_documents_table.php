<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The files that instruct a pleading — the contract, the power of attorney, the
 * receipts, the report.
 *
 * A row of its own, unlike the defendant: a pleading carries many documents,
 * each with its own description, and the lawyer adds and removes them one at a
 * time. Inlining that would mean a JSON column nobody can index or join.
 *
 * `name` keeps the original filename, extension included, and `extension`
 * repeats that suffix on purpose. Both are read far more often than they are
 * written — a badge on every row, a filter by kind — and neither should cost a
 * string operation per line.
 *
 * There is no `disk` and no `path` yet. Nothing is stored: the form holds the
 * files in the browser until the Action that saves the whole pleading exists,
 * and a column pointing at nowhere would only claim otherwise. It arrives with
 * the upload, together with whatever checksum that flow decides it needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Cascade, like the pleading itself: an attachment has no existence
            // outside the account that filed it, nor outside the pleading it
            // instructs. Deleting either takes the document with it.
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('legal_case_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // What the lawyer writes about the file, as opposed to what the
            // file already says about itself. Nullable because the description
            // is a convenience — a document identifies itself by its name.
            $table->text('description')->nullable();

            $table->string('extension', 16);

            // Bytes, as the browser reports them. Big integer rather than
            // integer: the limit the form enforces is a product decision and
            // may be raised, and the column should not be what stops it.
            $table->unsignedBigInteger('size');

            $table->timestamps();
            $table->softDeletes();

            // Every listing starts from the account, then narrows to one
            // pleading — the same shape as the indexes on `legal_cases`.
            $table->index(['account_id', 'legal_case_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
