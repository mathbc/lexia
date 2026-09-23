<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rulings the courts have already handed down in cases like this one.
 *
 * The seventh step's table — "Análise de Jurisprudência" — and the sibling of
 * `legal_precedents`, which is close enough that the split has to be justified.
 * A precedent is a *finding about this pleading*: it hangs off the thesis it
 * sustains, and carries `adherence` and `grounding`, which are claims somebody
 * made about these facts. A court decision is the **document**: what that
 * tribunal decided, transcribed from the catalogue, with no claim attached.
 * Hence no `legal_thesis_id`, no score and no reasoning column here — a lawyer
 * reads the ementa and decides what it is worth, and inventing a number for
 * that would be inventing the reading.
 *
 * The columns are the LexML record, one to one. A page under
 * `lexml.gov.br/urn/...` prints Localidade, Autoridade, Título, Data, Ementa,
 * Assuntos and the Nome Uniforme, and those are `locality`, `authority`,
 * `title`, `decided_at`, `summary`, `subject` and `urn`. Following the
 * catalogue's own vocabulary is what makes a transcription checkable by eye
 * against the page it came from — which, for a table filled by a model, is the
 * cheapest audit there is.
 *
 * `source_url` is not nullable, and it is the only column here that could not
 * have been anything else. It holds the `/urn/` page that was read, and it is
 * what `CourtDecisionSources::isRecord()` checks on the way in: a ruling nobody
 * can open is one nobody can confirm, and a fabricated ementa is shaped exactly
 * like a real one. A row without it would be an assertion with no way back to
 * its source.
 *
 * `urn` is nullable although the portal always prints one, because the guard
 * that matters is on `source_url` and losing a confirmed ruling over a missing
 * identifier would be the worse trade. When present it is the canonical name of
 * the document — "urn:lex:br:superior.tribunal.justica;turma.6:acordao;resp:1998-04-28;143513-288332"
 * — which is what makes deduplication across pleadings possible later.
 *
 * `decided_at` is a date and not a timestamp: a judgment has a day, not an
 * hour. Nullable because the record occasionally omits it, and because a null
 * here reads as "the page did not say" rather than as a guessed date.
 *
 * `summary` and `title` are `text()` and not `string()`, since an ementa is a
 * paragraph and some of them are very long. `subject` stays flat text rather
 * than jsonb: the portal hands the Assuntos over as a comma-separated line, and
 * no screen filters by term yet — the day one does, splitting a text column is
 * a migration, while guessing the structure now is a shape nobody asked for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('court_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Cascade, like the theses and the requirements: a ruling cited
            // here has no existence outside the account that found it, nor
            // outside the pleading it was found for.
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('legal_case_id')->constrained()->cascadeOnDelete();

            $table->text('title');
            $table->text('locality')->nullable();
            $table->text('authority')->nullable();
            $table->text('summary');
            $table->text('subject')->nullable();

            // A procedência, e a única coluna que a guarda consulta. Ver o
            // docblock acima para por que ela não é nullable.
            $table->text('source_url');
            $table->text('urn')->nullable();

            $table->date('decided_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['account_id', 'legal_case_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('court_decisions');
    }
};
