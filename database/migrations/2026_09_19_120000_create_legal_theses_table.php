<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the pleading argues — the content of the forensic review step.
 *
 * "Do Cabimento da Exceção de Pré-Executividade sem Garantia do Juízo",
 * "Da Ilegitimidade Passiva do Sócio-Administrador". Each row is one line of
 * argument the pleading will carry, and a pleading carries several: a
 * preliminary matter, the merits, an alternative reading of the merits, an
 * ancillary claim. Rows rather than a JSON column, for the same reason as
 * `requirements`: they are written and removed one at a time, and a JSON blob
 * would be neither indexable nor joinable — and here it would also have nothing
 * for `legal_precedents` to point at.
 *
 * `name` is text and not a string with a length: a thesis is titled with a whole
 * sentence, the same shape `requirements.description` has.
 *
 * `description` says what is argued and `impact` what winning it buys — "Garante
 * o conhecimento imediato da defesa sem bloqueio de bens da empresa." The second
 * is nullable and the first is not: a thesis with no argument is not a thesis,
 * while the procedural gain is a reading on top of it, and a lawyer can register
 * the thesis before being able to say what it costs the other side.
 *
 * `legal_bases` is the fundamentação the thesis rests on — articles, statutes,
 * súmulas, ADCs and temas — as a typed list rather than a sentence, because the
 * screen draws one chip per item and a future listing will want to group them by
 * court. Nullable rather than defaulted to `[]`, like the column of the same
 * name on `procedural_classes`: a thesis nobody has grounded yet and a thesis
 * that rests on nothing are different states, and only the second is a finding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_theses', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Cascade, like the requirements: a thesis has no existence outside
            // the account that argued it, nor outside the pleading it argues.
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('legal_case_id')->constrained()->cascadeOnDelete();

            $table->text('name');

            // A bare string and not the enum: a migration is a historical
            // record and does not couple itself to LegalThesisType, which will
            // still change. Nullable because the form saves one step at a time
            // and a thesis whose kind is undecided is a real state.
            $table->string('type', 30)->nullable();

            $table->text('description');
            $table->text('impact')->nullable();

            // jsonb and never json: it is the type Postgres can index, and the
            // one every other JSON column in this schema already uses.
            $table->jsonb('legal_bases')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Every listing starts from the account, then narrows to one
            // pleading — the same shape as the index on `requirements`.
            $table->index(['account_id', 'legal_case_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_theses');
    }
};
