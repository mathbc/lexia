<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The drafted document itself — the pleading as it will be filed.
 *
 * Every other child of `legal_cases` holds a piece the lawyer assembled: a
 * request, a thesis, a ruling. This one holds the **prose that assembles them**,
 * written by an agent from everything the six steps collected and then edited by
 * hand. `content` is one long text and not a set of columns per section, because
 * what the screen offers is a single textarea: the lawyer rewrites a paragraph in
 * the middle of "DOS FATOS" without the system needing to know it was that
 * section, and a schema of sections would have to be right about a document whose
 * shape varies with the procedural class.
 *
 * `version` is why this is a table and not a column on `legal_cases`. Editing the
 * draft never overwrites it: a save writes a new row, so the text the agent
 * produced survives beside the text the lawyer settled on, and a paragraph
 * deleted by accident is still somewhere. The unique index on
 * `(legal_case_id, version)` is what makes that numbering a fact rather than a
 * hope — two concurrent saves cannot both mint version 4.
 *
 * **No `softDeletes()`, and the absence is deliberate.** The sibling tables carry
 * it because their rows are removed one at a time by a screen that offers the
 * gesture; a version is append-only history, and nothing in the product deletes
 * one. A `deleted_at` here would be a column no code ever writes, and it would
 * quietly break the version numbering the day someone did write it — `max(version)`
 * would skip the soft-deleted row and collide with it on the unique index.
 *
 * `account_id` repeats what `legal_case_id` already implies, exactly as it does on
 * `legal_theses` and `requirements`: it is what lets AccountScope narrow the table
 * without a join on every read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_pleadings', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Cascade, like every other child: a draft has no existence outside
            // the account that wrote it, nor outside the pleading it is.
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('legal_case_id')->constrained()->cascadeOnDelete();

            // longText and not text: a petição inicial runs to several pages, and
            // this is the same type `legal_cases.facts` already uses for prose
            // with no ceiling worth naming.
            $table->longText('content');

            // Counts from 1 and never from 0: "versão 1" is what the screen says,
            // and an unsigned integer is the honest width for a number that only
            // ever increments.
            $table->unsignedInteger('version');

            $table->timestamps();

            // Toda listagem parte da conta e estreita para uma peça — a mesma
            // forma do índice de `legal_theses`.
            $table->index(['account_id', 'legal_case_id']);

            // A numeração é um fato, não uma esperança: duas gravações
            // simultâneas não podem cunhar a mesma versão.
            $table->unique(['legal_case_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_pleadings');
    }
};
