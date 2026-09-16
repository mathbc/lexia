<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The pleadings an account drafts for its clients.
 *
 * Who it belongs to, for whom, where in the CNJ taxonomy it sits, and who it
 * is against. The drafted text, the subject and the pleading type come once
 * the assembly flow is settled.
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

            $this->defendant($table);

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

    /**
     * The other party, described inline rather than as a row of its own.
     *
     * A defendant belongs to the pleading and to nothing else: there is no
     * listing of defendants, no reuse across matters, and the same person sued
     * twice is two independent descriptions — one per case, each frozen at the
     * time of drafting. A table would only buy joins nobody asks for.
     *
     * Every column is nullable, and that is the point rather than an
     * oversight. A client is registered once they are fully known; a defendant
     * is often the opposite — a name and a licence plate, a company with no
     * address yet. `notes` is where that partial knowledge goes, so the form
     * never has to refuse what the lawyer actually has.
     */
    private function defendant(Blueprint $table): void
    {
        $table->string('defendant_name')->nullable();

        // One column for both documents: unlike a customer, the defendant is
        // described, not registered, so there is no uniqueness to enforce per
        // kind and nothing downstream branches on which one it is. Digits
        // only — 11 for a CPF, 14 for a CNPJ — and the length is what tells
        // them apart when a screen needs to.
        $table->string('defendant_document', 14)->nullable();

        $table->string('defendant_email')->nullable();
        $table->string('defendant_phone', 20)->nullable();

        // Same shape as the customer address, minus the NOT NULLs: serving
        // process needs it, but it is frequently the thing being looked for.
        $table->char('defendant_postal_code', 8)->nullable();
        $table->string('defendant_street')->nullable();
        $table->string('defendant_number', 20)->nullable();
        $table->string('defendant_complement', 100)->nullable();
        $table->string('defendant_district', 120)->nullable();
        $table->string('defendant_city', 120)->nullable();
        $table->char('defendant_state', 2)->nullable();

        // Free text on purpose: a licence plate, an employer, a social media
        // handle, a habit of being home on Tuesdays. Anything that helps find
        // the defendant, none of which is worth a column.
        $table->text('defendant_notes')->nullable();
    }
};
