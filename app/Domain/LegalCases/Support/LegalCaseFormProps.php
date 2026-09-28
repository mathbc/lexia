<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Support;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\CourtDecisions\Models\CourtDecision;
use App\Domain\LegalCases\Data\InjunctiveReliefSuggestionData;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalPrecedents\Models\LegalPrecedent;
use App\Domain\LegalThemes\Models\GeneralRepercussion;
use App\Domain\LegalThemes\Models\LegalCaseTheme;
use App\Domain\LegalThemes\Models\LegalTheme;
use App\Domain\LegalTheses\Models\LegalThesis;
use App\Domain\Requirements\Models\Requirement;

/**
 * A saved pleading, shaped for the assembly form to reopen.
 *
 * The sibling of LegalCasePageProps, which answers what the actor may do; this
 * one answers what is already written. It is a projection and not the model:
 * the form receives the fields it draws and nothing else, and each group
 * arrives under the key the matching fields component already expects, so the
 * screen hydrates without translating anything. The first step is the top
 * level itself: the narrative and the injunction sit beside the addressing,
 * because that is the form they are saved with.
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
            'facts' => self::text($legalCase->facts),
            'injunctive_relief' => $legalCase->injunctive_relief,
            'injunctive_relief_description' => self::text($legalCase->injunctive_relief_description),
            // Relido pela classe que o escreveu, para que um envelope gravado
            // antes de uma mudança de forma chegue à tela na forma de hoje.
            'injunctive_relief_suggestion' => InjunctiveReliefSuggestionData::fromArray(
                $legalCase->injunctive_relief_suggestion,
            )?->toArray(),
            'current_step' => $legalCase->current_step->value,
            'is_draft' => $legalCase->is_draft,
            'defendant' => self::defendant($legalCase),
            'requirements' => self::requirements($legalCase),
            'theses' => self::theses($legalCase),
            'precedents' => self::precedents($legalCase),
            'research' => self::research($legalCase),
            'themes' => self::themes($legalCase),
            'theme_research' => self::findings($legalCase->theme_findings),
            'court_decisions' => self::courtDecisions($legalCase),
            'court_decision_research' => self::findings($legalCase->court_decision_findings),
        ];
    }

    /**
     * The account of the research run that produced the theses above.
     *
     * Everything a run returns except the two lists that became rows: the
     * question, the official pages opened, what stayed unresolved, and the
     * citations the guard refused. It lives in one jsonb column because one
     * screen reads it whole and nothing queries it.
     *
     * **Null is the signal, not an absence of detail.** It means this pleading
     * has never been researched, and it is what makes step 5 call the agent
     * when it opens. Present-but-empty is the opposite statement — a run
     * happened and confirmed nothing — and the step must not ask again for that
     * one, which is why the screen keys on this and never on `theses` being
     * empty.
     *
     * Read straight off the column with no reshaping: it was written by
     * `LegalResearchData::findings()`, which is the shape the screen speaks.
     * The one guard is against a row written before the column existed, where
     * the cast hands back something that is not an array.
     *
     * @return array<string, mixed>|null
     */
    private static function research(LegalCase $legalCase): ?array
    {
        return self::findings($legalCase->research_findings);
    }

    /**
     * One research run's account, or null when there has never been one.
     *
     * Shared by the two steps that research, because the rule is the same and
     * it is the whole of it: **null is the signal**, and the screen keys the
     * trigger on it rather than on the list being empty. The guard is against a
     * row written before the column existed, where the cast hands back
     * something that is not an array.
     *
     * @param  array<string, mixed>|null  $findings
     * @return array<string, mixed>|null
     */
    private static function findings(?array $findings): ?array
    {
        return is_array($findings) && $findings !== [] ? $findings : null;
    }

    /**
     * The STJ themes the pleading leans on — the fifth step's second tab.
     *
     * Read-only catalogue beside the one claim that is ours, the pivot's
     * `reason`. That is why the labels go out already in Portuguese, from the
     * enums, rather than as values beside an options prop the way the theses'
     * types do: nothing on the screen edits a theme's type, and the tab only
     * draws it.
     *
     * The `id` is the theme's, not the link's: it is what the "Concluir" posts
     * back as `legal_theme_id`, and what `sync()` keys the links by. The vector
     * is never selected — 768 floats per row for a screen that reads text.
     *
     * In the relation's order, which is the selection's ranking — the most
     * relevant theme first. The screen draws them in it and the "Concluir"
     * posts the kept ones back in it, which is how the ranking survives the
     * lawyer's curation.
     *
     * @return list<array<string, mixed>>
     */
    private static function themes(LegalCase $legalCase): array
    {
        return $legalCase->themes()
            ->select([
                'legal_themes.id',
                'legal_themes.type',
                'legal_themes.number',
                'legal_themes.status',
                'legal_themes.judging_body',
                'legal_themes.question',
                'legal_themes.settled_thesis',
                'legal_themes.judgment_scope',
            ])
            ->with('generalRepercussions')
            ->get()
            ->map(static fn (LegalTheme $theme): array => [
                'id' => $theme->id,
                'heading' => $theme->heading(),
                'status' => $theme->status,
                'judging_body' => $theme->judging_body?->label(),
                'question' => $theme->question,
                'settled_thesis' => $theme->settled_thesis,
                'judgment_scope' => $theme->judgment_scope,
                'reason' => self::reasonOf($theme),
                'general_repercussions' => $theme->generalRepercussions
                    ->map(static fn (GeneralRepercussion $repercussion): array => [
                        'number' => $repercussion->number,
                        'description' => $repercussion->description,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * The link's claim about the theme, read off the pivot.
     */
    private static function reasonOf(LegalTheme $theme): ?string
    {
        $pivot = $theme->getRelationValue('pivot');
        $reason = $pivot instanceof LegalCaseTheme ? $pivot->reason : null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /**
     * The case law of the sixth step, with the real ids.
     *
     * The rows as the LexML record wrote them, in the shape
     * `CourtDecisionData::toArray()` publishes and `SaveLegalCaseCourtDecisions`
     * accepts — so a run that has just happened and a pleading reopened a week
     * later hydrate through the same code, exactly as the theses do.
     *
     * The ids are the persisted ones, which is what makes concluding update the
     * rows instead of replacing them, and `decided_at` goes out as `Y-m-d`
     * rather than as a Carbon: the screen formats it, and a serialised
     * timestamp would arrive with an hour a judgment does not have.
     *
     * @return list<array<string, mixed>>
     */
    private static function courtDecisions(LegalCase $legalCase): array
    {
        return $legalCase->courtDecisions
            ->map(static fn (CourtDecision $decision): array => [
                'id' => $decision->id,
                'title' => $decision->title,
                'locality' => $decision->locality,
                'authority' => $decision->authority,
                'summary' => $decision->summary,
                'subject' => $decision->subject,
                'source_url' => $decision->source_url,
                'urn' => $decision->urn,
                'decided_at' => $decision->decided_at?->toDateString(),
            ])
            ->all();
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
     * The forensic review as the fifth step draws it, with the real ids.
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
