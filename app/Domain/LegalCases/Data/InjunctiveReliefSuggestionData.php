<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Data;

use App\Domain\LegalCases\Enums\InjunctiveReliefKind;

/**
 * What the urgent-relief agent made of a matter: whether to ask for a *tutela
 * de urgência*, of which species, and the text the lawyer is offered for it.
 *
 * It is a **suggestion**, and the shape keeps it one. The pleading's decision
 * still lives where it always did — `legal_cases.injunctive_relief` and the
 * description beside it, which the lawyer saves in the first step. This value
 * is what pre-fills them and, once saved, the provenance kept next to them in
 * `injunctive_relief_suggestion`: what the AI said, and when. That is what lets
 * the screen go on saying "Sugestão da IA" after a reload, and say "editada"
 * once the text in the box no longer matches the one suggested.
 *
 * ## The text is composed here, not by the model
 *
 * The agent answers in parts — the measure, the species, the statute, the
 * probability of the right, the danger, the reversibility — and `description`
 * is those parts laid out in labelled paragraphs. Two reasons, both about the
 * box the text lands in. The lawyer edits one textarea, so the parts have to
 * arrive as one text; and the drafting agent reads that same text to write the
 * section DA TUTELA DE URGÊNCIA, so the labels are what tell it which paragraph
 * is the measure and which is the danger. A model asked to write the labels
 * itself would write them differently every time.
 *
 * Reversibility is dropped from the text for the precautionary species on
 * purpose: art. 300, §3º, bars only the anticipatory one, and a petição that
 * argues the reversibility of an asset freeze argues something nobody asked.
 *
 * ## The two normalisations
 *
 * 1. **Not recommended means nothing else.** A model that says no and fills the
 *    measure anyway is saying two things; the "no" wins, and the justification
 *    — which says which requirement failed — is all that survives.
 * 2. **Recommended without a measure is not a recommendation.** The grammar can
 *    force the key to be there, not a sentence into it, and a request with no
 *    measure is a request the judge cannot grant.
 *
 * ## The money
 *
 * `unsupportedAmounts` is the guard RefinedFactsData runs, for the same reason
 * and in the same form: the text is prose, so a figure the narrative does not
 * write is **reported**, never cut. The agent is told to leave a daily fine or
 * a freeze ceiling as a bracketed gap; this is what catches it when it does
 * not.
 */
final readonly class InjunctiveReliefSuggestionData
{
    /**
     * Mais documentos do que um pedido de urgência se instrui.
     *
     * É o mesmo teto que o schema do agente impõe; repetido aqui porque o
     * envelope também volta do navegador, onde não há gramática nenhuma.
     */
    public const int MAX_EVIDENCE = 5;

    /**
     * Every key `toArray()` writes, with the value an envelope saved before it
     * existed would have — so a missing key reads as absent, not as an error.
     *
     * @var array<string, mixed>
     */
    private const array ENVELOPE = [
        'recommended' => false,
        'kind' => null,
        'description' => null,
        'justification' => null,
        'evidence' => [],
        'unsupported_amounts' => [],
        'suggested_at' => null,
    ];

    /**
     * @param  list<string>  $evidence  what should instruct the request, in the lawyer's terms
     * @param  list<string>  $unsupportedAmounts  figures the text names and the narrative does not
     */
    public function __construct(
        public bool $recommended,
        public ?InjunctiveReliefKind $kind,
        public ?string $description,
        public string $justification,
        public array $evidence,
        public array $unsupportedAmounts,
        public string $suggestedAt,
    ) {}

    /**
     * The agent's answer, under the keys its schema declares, with the
     * narrative it read — the only thing that can say whether a figure existed
     * before the model wrote it.
     *
     * @param  array<string, mixed>  $answer
     */
    public static function fromAgent(array $answer, string $facts): self
    {
        $measure = self::text($answer['measure'] ?? null);
        $justification = (string) self::text($answer['justification'] ?? null);

        if (! self::truthy($answer['recommended'] ?? false) || $measure === null) {
            return self::declined($justification);
        }

        $kind = InjunctiveReliefKind::tryFrom((string) self::text($answer['kind'] ?? null));
        $description = self::describe($measure, $kind, $answer);

        return new self(
            recommended: true,
            kind: $kind,
            description: $description,
            justification: $justification,
            evidence: self::evidenceIn($answer['evidence'] ?? null),
            unsupportedAmounts: PleadingDraftData::amountsMissingFrom($description, $facts),
            suggestedAt: now()->toIso8601String(),
        );
    }

    /**
     * The envelope as `toArray()` wrote it — back from the browser when the
     * first step is saved, or out of the jsonb column when it reopens.
     *
     * Read as loosely as the other side can vary and nothing more: it is the
     * record of an answer, not the answer, so it is never recomputed. Null, or
     * an empty array, is "no suggestion was ever asked for".
     *
     * @param  array<string, mixed>|null  $envelope
     */
    public static function fromArray(?array $envelope): ?self
    {
        if ($envelope === null || $envelope === []) {
            return null;
        }

        $envelope += self::ENVELOPE;
        $justification = (string) self::text($envelope['justification']);
        $suggestedAt = (string) self::text($envelope['suggested_at']);

        if (! self::truthy($envelope['recommended'])) {
            return self::declined($justification, $suggestedAt);
        }

        return new self(
            recommended: true,
            kind: InjunctiveReliefKind::tryFrom((string) self::text($envelope['kind'])),
            description: self::text($envelope['description']),
            justification: $justification,
            evidence: self::evidenceIn($envelope['evidence']),
            unsupportedAmounts: self::listOf($envelope['unsupported_amounts']),
            suggestedAt: $suggestedAt,
        );
    }

    /**
     * @return array{recommended: bool, kind: string|null, kind_label: string|null, description: string|null, justification: string, evidence: list<string>, unsupported_amounts: list<string>, suggested_at: string}
     */
    public function toArray(): array
    {
        return [
            'recommended' => $this->recommended,
            'kind' => $this->kind?->value,
            'kind_label' => $this->kind?->label(),
            'description' => $this->description,
            'justification' => $this->justification,
            'evidence' => $this->evidence,
            'unsupported_amounts' => $this->unsupportedAmounts,
            'suggested_at' => $this->suggestedAt,
        ];
    }

    private static function declined(string $justification, ?string $suggestedAt = null): self
    {
        return new self(
            recommended: false,
            kind: null,
            description: null,
            justification: $justification,
            evidence: [],
            unsupportedAmounts: [],
            suggestedAt: $suggestedAt ?? now()->toIso8601String(),
        );
    }

    /**
     * The parts laid out as the paragraphs the lawyer edits and the drafting
     * agent reads, in the order a petição argues them.
     *
     * @param  array<string, mixed>  $answer
     */
    private static function describe(string $measure, ?InjunctiveReliefKind $kind, array $answer): string
    {
        return implode(PHP_EOL.PHP_EOL, array_filter([
            self::paragraph('Medida pretendida', $measure),
            self::paragraph('Espécie', $kind === null ? null : 'tutela de urgência '.mb_strtolower($kind->label())),
            self::paragraph('Fundamento legal', self::text($answer['legal_basis'] ?? null)),
            self::paragraph('Probabilidade do direito', self::text($answer['probability'] ?? null)),
            self::paragraph('Perigo de dano', self::text($answer['danger'] ?? null)),
            self::paragraph(
                'Reversibilidade',
                $kind === InjunctiveReliefKind::Precautionary ? null : self::text($answer['reversibility'] ?? null),
            ),
        ]));
    }

    private static function paragraph(string $label, ?string $body): ?string
    {
        if ($body === null) {
            return null;
        }

        return $label.': '.(preg_match('/[.;!?]$/u', $body) === 1 ? $body : $body.'.');
    }

    /**
     * @return list<string>
     */
    private static function evidenceIn(mixed $evidence): array
    {
        return array_slice(self::listOf($evidence), 0, self::MAX_EVIDENCE);
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

    /**
     * A boolean, also when a provider sends it as "true".
     */
    private static function truthy(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL) === true;
    }

    private static function text(mixed $value): ?string
    {
        $trimmed = trim(is_string($value) ? $value : '');

        return $trimmed === '' ? null : $trimmed;
    }
}
