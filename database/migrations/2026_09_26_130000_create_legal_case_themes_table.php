<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which STJ themes a pleading leans on — the second tab of the sixth step.
 *
 * The N-N between `legal_cases` and `legal_themes`, and a table with a model of
 * its own (`LegalCaseTheme`) rather than a bare pivot, because the link carries
 * a claim: `reason` is why *this* theme applies to *these* facts, written by
 * `LegalThemeSelectionAgent`. Tema 1016 exists once in the catalogue and once
 * per pleading here, and what it does for the case changes from one row to the
 * next — the same argument `legal_precedents` makes for keeping the finding
 * apart from the súmula.
 *
 * `account_id` although the pleading already has one, like every other child
 * of `legal_cases`: the account is the boundary, and a row that belongs to a
 * tenant says so in its own columns. It is written explicitly by
 * `SaveLegalCaseThemes`, since the rows are reached only through the pleading
 * and never queried on their own.
 *
 * **No soft deletes, unlike the theses and the court decisions**, and the
 * difference is deliberate. Those rows are content — text an agent wrote or a
 * lawyer edited, which a soft delete keeps recoverable. This row is a link to a
 * catalogue entry that never goes away, so there is nothing to recover; and a
 * second research run re-links the same theme often, which would collide on
 * the unique index with a row that was only logically deleted.
 *
 * The theme side cascades too. `ImportLegalThemes` never deletes a theme (the
 * STJ cancels instead), so it is the hard wipe of the catalogue that this
 * answers — and a link to a theme that no longer exists is not worth blocking
 * that for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_case_themes', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Cascade, como as teses e os julgados: o vínculo não existe fora
            // da conta que o fez, nem fora da peça em que foi feito.
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('legal_case_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('legal_theme_id')->constrained()->cascadeOnDelete();

            // O porquê, escrito pelo agente. Nullable porque o payload do
            // "Concluir" pode trazer a linha sem ele, e um vínculo sem razão
            // declarada continua sendo um vínculo.
            $table->text('reason')->nullable();

            $table->timestamps();

            // Um tema entra uma vez por peça: é o que torna o `sync()` da
            // gravação um diff, e não uma lista que cresce a cada rodada.
            $table->unique(['legal_case_id', 'legal_theme_id']);
            $table->index(['account_id', 'legal_case_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_case_themes');
    }
};
