<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Support;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalPrecedents\Models\LegalPrecedent;
use App\Domain\LegalTheses\Models\LegalThesis;
use App\Domain\Requirements\Models\Requirement;

/**
 * A saved pleading, shaped for the assembly form to reopen.
 *
 * The sibling of LegalCasePageProps, which answers what the actor may do; this
 * one answers what is already written. It is a projection and not the model:
 * the form receives the fields it draws and nothing else, and each group
 * arrives under the key the matching fields component already expects, so the
 * screen hydrates without translating anything.
 *
 * Two shapes are deliberate. The practice area goes out as a **slug**, because
 * that is the currency the picker and the query string work in — the uuid is
 * generated on load and differs between databases. And the amounts go out
 * masked, because the field that holds them is a masked text input: the
 * decimal string the column stores would arrive in the box reading "50000.00".
 */
final class LegalCaseFormProps
{
    /**
     * @return array<string, mixed>
     */
    public static function draft(LegalCase $legalCase): array
    {
        return [
            'id' => $legalCase->id,
            'customer_id' => $legalCase->customer_id,
            'practice_area' => $legalCase->practiceArea->slug,
            'procedural_class_id' => $legalCase->procedural_class_id,
            'court_addressing' => self::text($legalCase->court_addressing),
            'current_step' => $legalCase->current_step->value,
            'is_draft' => $legalCase->is_draft,
            'defendant' => self::defendant($legalCase),
            'facts' => self::facts($legalCase),
            'requirements' => self::requirements($legalCase),
            'theses' => self::theses($legalCase),
            'precedents' => self::precedents($legalCase),
        ];
    }

    /**
     * Empty strings rather than nulls: these feed controlled inputs, and React
     * would call a null-valued input uncontrolled and warn about it.
     *
     * The coalescing lives in `text()` instead of being repeated on every line
     * — twelve of them in a row scored 13 on cyclomatic complexity, over the
     * project's limit, for what is a single rule applied twelve times.
     *
     * @return array<string, string>
     */
    private static function defendant(LegalCase $legalCase): array
    {
        return [
            'defendant_name' => self::text($legalCase->defendant_name),
            'defendant_document' => self::text($legalCase->defendant_document),
            'defendant_email' => self::text($legalCase->defendant_email),
            'defendant_phone' => self::text($legalCase->defendant_phone),
            'defendant_postal_code' => self::text($legalCase->defendant_postal_code),
            'defendant_street' => self::text($legalCase->defendant_street),
            'defendant_number' => self::text($legalCase->defendant_number),
            'defendant_complement' => self::text($legalCase->defendant_complement),
            'defendant_district' => self::text($legalCase->defendant_district),
            'defendant_city' => self::text($legalCase->defendant_city),
            // Explícito em vez de `?->value ?? ''`: a UF é a única coluna do
            // réu que não é string, e o caso nulo merece aparecer.
            'defendant_state' => $legalCase->defendant_state instanceof BrazilianState
                ? $legalCase->defendant_state->value
                : '',
            'defendant_notes' => self::text($legalCase->defendant_notes),
        ];
    }

    private static function text(?string $value): string
    {
        return $value ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function facts(LegalCase $legalCase): array
    {
        return [
            'facts' => self::text($legalCase->facts),
            'injunctive_relief' => $legalCase->injunctive_relief,
            'injunctive_relief_description' => self::text($legalCase->injunctive_relief_description),
        ];
    }

    /**
     * The requests with their real ids, so re-saving updates the rows instead
     * of replacing them.
     *
     * @return list<array{id: string, description: string, amount: string}>
     */
    private static function requirements(LegalCase $legalCase): array
    {
        return $legalCase->requirements
            ->map(static fn (Requirement $requirement): array => [
                'id' => $requirement->id,
                'description' => $requirement->description,
                'amount' => self::mask($requirement->amount),
            ])
            ->all();
    }

    /**
     * The forensic review as the sixth step draws it, with the real ids.
     *
     * Until a pleading could be concluded there was nothing to send: the theses
     * arrived from the research, lived in `sessionStorage` and died with the
     * tab. Now they are rows, and without this the step would open empty on a
     * pleading that plainly has theses — telling the lawyer there was no
     * research in this session about work that is sitting in the database.
     *
     * The shape is the **flat** one the screen already speaks: two lists, with
     * the precedent naming its thesis by `legal_thesis_id`. That is what
     * `LegalResearchData` publishes and what `SaveLegalCaseForensicReview`
     * accepts, so a saved review and a fresh one hydrate through exactly the
     * same code — `toThesisDrafts()` in `@/lib/forensic-review` re-nests both.
     *
     * The ids are the persisted ones and not minted here, which is what makes
     * re-saving update the rows instead of replacing them — the same reason
     * `requirements()` sends them.
     *
     * @return list<array<string, mixed>>
     */
    private static function theses(LegalCase $legalCase): array
    {
        return $legalCase->theses
            ->map(static fn (LegalThesis $thesis): array => [
                'id' => $thesis->id,
                'name' => $thesis->name,
                'type' => $thesis->type?->value,
                'description' => $thesis->description,
                'impact' => $thesis->impact,
                'legal_bases' => $thesis->legal_bases ?? [],
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function precedents(LegalCase $legalCase): array
    {
        return $legalCase->precedents
            ->map(static fn (LegalPrecedent $precedent): array => [
                'id' => $precedent->id,
                'legal_thesis_id' => $precedent->legal_thesis_id,
                'name' => $precedent->name,
                'type' => $precedent->type?->value,
                'description' => $precedent->description,
                'citation' => $precedent->citation,
                'grounding' => $precedent->grounding,
                'adherence' => $precedent->adherence,
            ])
            ->all();
    }

    /**
     * "50000.00" back into "50.000,00", which is what the field draws.
     */
    private static function mask(?string $amount): string
    {
        if ($amount === null) {
            return '';
        }

        return number_format((float) $amount, 2, ',', '.');
    }
}
