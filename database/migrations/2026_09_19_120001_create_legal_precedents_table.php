<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rulings that sustain what the pleading argues.
 *
 * "STJ — Súmula nº 393/STJ", and under it the text it consolidated: "A exceção
 * de pré-executividade é admissível na execução fiscal relativamente às matérias
 * conhecíveis de ofício que não demandem dilação probatória." A pleading cites
 * several, and each belongs to this pleading alone — the same ruling found for
 * two cases is two rows, because what is stored here is not the súmula but the
 * *finding*: how much it adheres to these facts and what it grounds in them.
 *
 * `legal_thesis_id` is the thesis it grounds, and is nullable: a ruling can be
 * found before the thesis it will sustain exists, and it stays a valid finding
 * without one. `nullOnDelete` rather than cascade, because losing the thesis
 * does not invalidate the judgment.
 *
 * That constraint is a belt to the Action's braces, and it is worth knowing
 * which of the two actually holds. Since LegalThesis is soft-deleted,
 * `$thesis->delete()` only writes `deleted_at` — the row stays and the foreign
 * key is never consulted, so the database nulls nothing. What unhooks a
 * precedent from a thesis the lawyer removed is the id map in
 * SaveLegalCaseForensicReview. The constraint is there for the hard delete —
 * `forceDelete()`, or a raw DELETE — so that path neither breaks the key nor
 * takes the ruling with it. It also says nothing about accounts: a
 * `legal_thesis_id` from another tenant is a perfectly valid row, and only the
 * Action's map refuses it.
 *
 * `citation` is the reference in ABNT, which is what the drafted pleading
 * prints, and `grounding` the sentence that says what the ruling does for this
 * case — "Fundamenta a ilegitimidade passiva do sócio-administrador…". Both are
 * nullable: a ruling can be registered before either has been written.
 *
 * `adherence` is how well it fits these facts, in percent. It is nullable, and
 * the null is meaningful in the way `requirements.amount` is: adherence is a
 * measurement somebody made about *this* case, not a property of the súmula, so
 * a ruling the lawyer typed in by hand has none — and a zero would read as
 * "measured, and irrelevant", which is a different claim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_precedents', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('legal_case_id')->constrained()->cascadeOnDelete();

            // `nullable()` before `constrained()`: afterwards it would apply to
            // the foreign key definition instead of the column.
            $table->foreignUuid('legal_thesis_id')->nullable()
                ->constrained('legal_theses')->nullOnDelete();

            $table->text('name');
            $table->string('type', 30)->nullable();
            $table->text('description');
            $table->text('citation')->nullable();
            $table->text('grounding')->nullable();

            // Decimal rather than integer because 93,5% is an answer a model
            // gives, and five digits leave room for the whole scale twice over.
            $table->decimal('adherence', 5, 2)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['account_id', 'legal_case_id']);

            // The one reading that starts from the thesis: its own precedents.
            $table->index('legal_thesis_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_precedents');
    }
};
