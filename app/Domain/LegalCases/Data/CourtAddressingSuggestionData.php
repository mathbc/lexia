<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Data;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\JudicialSystems\Data\JudicialSystemSelection;
use App\Domain\JudicialSystems\Enums\AdoptionStatus;
use App\Domain\LegalCases\Enums\CourtDivision;
use App\Domain\LegalCases\Enums\ForumSource;
use App\Domain\ProceduralClasses\Enums\JusticeBranch;

/**
 * What the addressing chain made of a matter: to whom the petição is
 * addressed, through which system it is filed, and why.
 *
 * It is a **suggestion**, the same arrangement as
 * InjunctiveReliefSuggestionData. The pleading's own fields stay where they
 * were — `court_addressing` and `judicial_system_id`, saved by the first step
 * —, and this value is what pre-fills them and, once saved, the provenance
 * kept beside them in `court_addressing_suggestion`. That is what lets the
 * screen go on saying "Sugestão da IA" after a reload, and "editada" once the
 * text no longer matches.
 *
 * ## The sentence is composed here, never by the model
 *
 * "Excelentíssimo(a) Senhor(a) Juiz(a) de Direito da Vara Cível da Comarca de
 * Joinville/SC" is three choices and two records. The agent chooses the court
 * (CourtDivision, which carries its branch), where the city comes from
 * (ForumSource) and the statutes; ForumPlace fetches the city; this class lays
 * them out. Two reasons, both about the document the line opens. The person on
 * the bench is unknown until distribution, so the formula is gender-neutral —
 * and a formula that depends on a model remembering it is one that comes back
 * masculine. And the comarca of a filed petição is a fact: the city is copied
 * from the client's or the defendant's record, or accepted from the narrative
 * only when the narrative writes it.
 *
 * What is not known becomes the bracketed gap the drafting agent already keeps
 * — `[CIDADE/UF]`, `[CIDADE]/SC` —, so the lawyer sees it and
 * PleadingDraftData counts it.
 *
 * ## The warnings
 *
 * Composed here, from the parts, rather than asked of the model: the city the
 * narrative does not write, the defendant with no city on record, a forum
 * outside the state courts the map covers, a system the chain could not
 * choose, and a court the map records as still moving to its system.
 */
final readonly class CourtAddressingSuggestionData
{
    /**
     * The opening of every suggested addressing. Neutral because the judge is
     * not known when the petition is written.
     */
    public const string SALUTATION = 'Excelentíssimo(a) Senhor(a)';

    /**
     * Every key `toArray()` writes that `fromArray()` reads back, with the
     * value an envelope saved before it existed would have.
     *
     * @var array<string, mixed>
     */
    private const array ENVELOPE = [
        'court_addressing' => null,
        'division' => null,
        'forum_source' => null,
        'city' => null,
        'state' => null,
        'legal_basis' => null,
        'justification' => null,
        'judicial_system' => null,
        'judicial_system_justification' => null,
        'warnings' => [],
        'suggested_at' => null,
    ];

    /**
     * @param  array{id: string, slug: string, name: string, court: string, status: string, status_label: string, source: string}|null  $judicialSystem
     * @param  list<string>  $warnings  what the lawyer must check before trusting the suggestion
     */
    public function __construct(
        public ?string $courtAddressing,
        public ?CourtDivision $division,
        public ?ForumSource $forumSource,
        public ?string $city,
        public ?BrazilianState $state,
        public ?string $legalBasis,
        public string $justification,
        public ?array $judicialSystem,
        public ?string $judicialSystemJustification,
        public array $warnings,
        public string $suggestedAt,
    ) {}

    /**
     * The chain's answer, laid out.
     *
     * `$division` null is the agent saying the forum is not a first-degree
     * court it can name — a tribunal's original competence, the electoral or
     * military courts —, and then there is no sentence to compose: the
     * justification says which body it is.
     */
    public static function compose(
        ?CourtDivision $division,
        ?ForumSource $source,
        ForumPlace $place,
        ?string $legalBasis,
        string $justification,
        ?JudicialSystemSelection $system,
    ): self {
        if ($division === null) {
            return self::outOfReach($justification);
        }

        return new self(
            courtAddressing: self::address($division, $place),
            division: $division,
            forumSource: $source,
            city: $place->city,
            state: $place->state,
            legalBasis: self::text($legalBasis),
            justification: $justification,
            judicialSystem: $system?->toArray(),
            judicialSystemJustification: $system?->justification,
            warnings: array_values(array_filter([
                $place->warning,
                self::systemWarning($division, $place->state, $system),
            ])),
            suggestedAt: now()->toIso8601String(),
        );
    }

    /**
     * No court to address, and the reason why — the answer for a class that
     * only runs where this list does not reach, and for an agent that says so.
     */
    public static function outOfReach(string $justification): self
    {
        return new self(
            courtAddressing: null,
            division: null,
            forumSource: null,
            city: null,
            state: null,
            legalBasis: null,
            justification: $justification,
            judicialSystem: null,
            judicialSystemJustification: null,
            warnings: [],
            suggestedAt: now()->toIso8601String(),
        );
    }

    /**
     * The envelope as `toArray()` wrote it — back from the browser when the
     * first step is saved, or out of the jsonb column when it reopens.
     *
     * The record of an answer, not the answer: read as loosely as the other
     * side can vary and never recomputed. Null, or an empty array, is "no
     * suggestion was ever asked for".
     *
     * @param  array<string, mixed>|null  $envelope
     */
    public static function fromArray(?array $envelope): ?self
    {
        if ($envelope === null || $envelope === []) {
            return null;
        }

        $envelope += self::ENVELOPE;

        return new self(
            courtAddressing: self::text($envelope['court_addressing']),
            division: CourtDivision::tryFrom((string) self::text($envelope['division'])),
            forumSource: ForumSource::tryFrom((string) self::text($envelope['forum_source'])),
            city: self::text($envelope['city']),
            state: BrazilianState::tryFrom((string) self::text($envelope['state'])),
            legalBasis: self::text($envelope['legal_basis']),
            justification: (string) self::text($envelope['justification']),
            judicialSystem: self::systemIn($envelope['judicial_system']),
            judicialSystemJustification: self::text($envelope['judicial_system_justification']),
            warnings: self::listOf($envelope['warnings']),
            suggestedAt: (string) self::text($envelope['suggested_at']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'court_addressing' => $this->courtAddressing,
            'division' => $this->division?->value,
            'division_label' => $this->division?->label(),
            'branch' => $this->division?->branch()->value,
            'branch_label' => $this->division?->branch()->label(),
            'forum_source' => $this->forumSource?->value,
            'forum_source_label' => $this->forumSource?->label(),
            'city' => $this->city,
            'state' => $this->state?->value,
            'legal_basis' => $this->legalBasis,
            'justification' => $this->justification,
            'judicial_system' => $this->judicialSystem,
            'judicial_system_justification' => $this->judicialSystemJustification,
            'warnings' => $this->warnings,
            'suggested_at' => $this->suggestedAt,
        ];
    }

    /**
     * "Excelentíssimo(a) Senhor(a) Juiz(a) Federal do Juizado Especial Federal
     * da Subseção Judiciária de Joinville/SC".
     */
    private static function address(CourtDivision $division, ForumPlace $place): string
    {
        return self::SALUTATION.' '.$division->judge().' '.$division->withArticle().' '.self::seat($division, $place);
    }

    /**
     * How each branch names the territory of a first-degree court: the comarca
     * of the state courts — the circunscrição in the Distrito Federal, whose
     * court organises itself that way —, the subseção of the federal ones, and
     * the plain city of a Vara do Trabalho.
     */
    private static function seat(CourtDivision $division, ForumPlace $place): string
    {
        $where = self::where($place);

        return match ($division->branch()) {
            JusticeBranch::Federal => 'da Subseção Judiciária de '.$where,
            JusticeBranch::Labor => 'de '.$where,
            default => ($place->state === BrazilianState::DF ? 'da Circunscrição Judiciária de ' : 'da Comarca de ').$where,
        };
    }

    /** "Joinville/SC", or the gap for whichever half is unknown. */
    private static function where(ForumPlace $place): string
    {
        if ($place->city === null && $place->state === null) {
            return '[CIDADE/UF]';
        }

        return ($place->city ?? '[CIDADE]').'/'.($place->state->value ?? '[UF]');
    }

    /**
     * What the lawyer must check about the system, if anything.
     *
     * The map covers the state courts alone, so any other branch is chosen by
     * hand; a state court with no answer is either a forum with no state or a
     * choice that failed; and a court still moving to its system may not have
     * moved in this comarca.
     */
    private static function systemWarning(CourtDivision $division, ?BrazilianState $state, ?JudicialSystemSelection $system): ?string
    {
        if ($division->branch() !== JusticeBranch::State) {
            return 'O mapa de sistemas cobre só os tribunais de justiça estaduais: escolha à mão o sistema da '
                .$division->branch()->label().'.';
        }

        if ($system === null) {
            return $state === null
                ? 'Sem a UF do foro não há como apontar o sistema judicial: escolha-o à mão.'
                : "Não foi possível apontar o sistema judicial do tribunal de {$state->value}: escolha-o à mão.";
        }

        return $system->status === AdoptionStatus::Active
            ? null
            : "O mapa registra o {$system->system->name} no {$system->court} como “".mb_strtolower($system->status->label())
                .'”: confira no portal do tribunal se a comarca já o usa.';
    }

    /**
     * @return array{id: string, slug: string, name: string, court: string, status: string, status_label: string, source: string}|null
     */
    private static function systemIn(mixed $system): ?array
    {
        if (! is_array($system) || self::text($system['id'] ?? null) === null) {
            return null;
        }

        $field = static fn (string $key): string => (string) self::text($system[$key] ?? null);

        return [
            'id' => $field('id'),
            'slug' => $field('slug'),
            'name' => $field('name'),
            'court' => $field('court'),
            'status' => $field('status'),
            'status_label' => $field('status_label'),
            'source' => $field('source'),
        ];
    }

    /**
     * @return list<string>
     */
    private static function listOf(mixed $items): array
    {
        return array_values(array_unique(array_filter(array_map(
            self::text(...),
            is_array($items) ? $items : [],
        ))));
    }

    private static function text(mixed $value): ?string
    {
        $trimmed = trim(is_string($value) ? $value : '');

        return $trimmed === '' ? null : $trimmed;
    }
}
