<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The legal scholarship a pleading may lean on — the third tab of the fifth step.
 *
 * The sibling of `court_decisions`, and close enough that the difference has to
 * be said. A court decision is what a tribunal *decided*; a doctrine row is what
 * a jurist *wrote* — a book, a journal article, a chapter —, catalogued by the
 * Rede Virtual de Bibliotecas and published in the LexML under
 * `urn:lex:br:rede.virtual.bibliotecas:...`. Like the ruling, it is the
 * **document** and not a finding: no score, no thesis, and no sentence about what
 * the author argues, because nobody here has read the work.
 *
 * The columns are the record, mapped to English. The page prints Tipo, Autor,
 * Título, Data, Ementa (a table of contents, when the library wrote one) and
 * Assuntos, which become `kind`, `author`, `title`, `published_in`, `summary`
 * and `subject`. `publication` is the one column composed rather than copied:
 * the periodical or parent work, the imprint and the volume and pages, read from
 * the record's "Publicação" block — the part of a bibliographic reference that
 * says where the text actually is.
 *
 * `author` is not nullable, and it is the guard the whole tab rests on. A
 * doctrine is cited *by its author*, and a work nobody can attribute is the
 * exact thing the research is told never to produce — `DoctrineData::isAttributable()`
 * refuses it on the way in.
 *
 * `published_in` is text and not a date: the catalogue writes a year, and for an
 * aggregated record a list of them ("1996, 1994, 1993"). Parsing that into a
 * date would invent a day and a month.
 *
 * `relevance` is the only column a model wrote, and the screen labels it as
 * such. It relates the *subject* of the work to the case and never says what
 * the author holds — the research prompt forbids that sentence.
 *
 * `source_url` is the `/urn/` page that was read, NOT NULL for the reason
 * `court_decisions` gives: a reference nobody can open is one nobody can check.
 *
 * `position` is the one column `court_decisions` does not have, and it is there
 * because the order is an answer here: the agent ranks the most specific work
 * first, and the five rows of a run are written within the same second, which
 * `timestamp(0)` cannot tell apart. `legal_case_themes.position` is the
 * precedent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctrines', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Cascade, como os julgados: uma obra encontrada aqui não existe
            // fora da conta que a achou nem da peça para a qual foi achada.
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('legal_case_id')->constrained()->cascadeOnDelete();

            $table->text('kind')->nullable();
            $table->text('author');
            $table->text('title');
            $table->text('published_in')->nullable();
            $table->text('summary')->nullable();
            $table->text('subject')->nullable();
            $table->text('publication')->nullable();
            $table->text('relevance')->nullable();

            // A procedência, e a coluna que a guarda consulta.
            $table->text('source_url');
            $table->text('urn')->nullable();

            // A ordem do agente — a obra mais específica primeiro —, que o
            // `created_at` não guarda: as cinco são gravadas no mesmo segundo.
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['account_id', 'legal_case_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctrines');
    }
};
