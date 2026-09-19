<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Data;

use App\Domain\Requirements\Data\RequirementData;

/**
 * The narrative rewritten, the reason it was given weight, and the figures in
 * it that the client never wrote.
 *
 * Not a `FactsData`, though it looks close to one. That value is what the third
 * step of the form **saves** — the narrative plus the injunction and its
 * justification — and this is what an agent **suggests** about the narrative
 * alone. Whether to ask for an injunction is a decision the lawyer makes and
 * this agent is told not to touch, so folding the two would mean carrying a
 * flag nobody here is entitled to set.
 *
 * `impactBasis` is the agent declaring itself. The emphasis it may add to a
 * narrative of irreparable loss is the one part of the answer a lawyer cannot
 * check by comparing texts — it is spread across the prose rather than sitting
 * in a field — so the agent is made to name the fact that authorised it. Null
 * is the ordinary answer: a debt, a late delivery or a contract between
 * companies gets no emphasis at all.
 *
 * ## The money the client did not write
 *
 * `unsupportedAmounts` is the same guard `RequirementListData::fromAgent()`
 * runs, in the only form prose allows. The measured failure is the same one the
 * requirement agent has: given "R$ 4.800,00 por mês" and "cinco meses em
 * aberto", a small model closes the paragraph with "totalizando R$ 24.000,00" —
 * a figure that is arithmetic rather than narrative, arrives well formed, and
 * is indistinguishable from one the client wrote. Naming the connectives in the
 * prompt ("totalizando", "no total de", "perfazendo") holds it far better than
 * naming the operation did, and still not always.
 *
 * What differs is what the guard may do about it. An amount is a column, so
 * there it is dropped and the field opens blank; a paragraph cannot be dropped
 * without leaving a hole in the middle of a story, and a narrative half refused
 * reads worse than one read carefully. So here the guard **reports**: every
 * figure the rewrite names and the relato does not, in the notation the amount
 * column speaks, for the screen to show beside the text it is offering. The
 * lawyer accepting a refinement is the defence, and this is what tells them
 * where to look.
 *
 * Only currency is checked, deliberately. "R$" is where a composed figure is
 * both likely and costly, while bare numbers change shape legitimately all the
 * time — "sete meses" into "7 meses" is a rewrite doing its job.
 */
final readonly class RefinedFactsData
{
    /**
     * @param  list<string>  $unsupportedAmounts  figures the rewrite names and the relato does not
     */
    public function __construct(
        public string $facts,
        public ?string $impactBasis,
        public array $unsupportedAmounts = [],
    ) {}

    /**
     * The refinement agent's answer, under the keys its schema declares, with
     * the narrative it was asked to rewrite.
     *
     * The narrative travels along for the same reason it travels into
     * `RequirementListData::fromAgent()`: it is the only thing that can say
     * whether a figure existed before the model wrote it.
     *
     * @param  array<string, mixed>  $answer
     * @param  string  $facts  the narrative, as the agent received it
     */
    public static function fromAgent(array $answer, string $facts): self
    {
        $refined = (string) self::nullify($answer['facts'] ?? null);

        return new self(
            facts: $refined,
            impactBasis: self::nullify($answer['impact_basis'] ?? null),
            unsupportedAmounts: self::amountsMissingFrom($refined, $facts),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'facts' => $this->facts,
            'impact_basis' => $this->impactBasis,
            'unsupported_amounts' => $this->unsupportedAmounts,
        ];
    }

    /**
     * Whether this rewrite names money that the client's own account does not.
     *
     * Not a reason to throw it away: the sentence around the figure is usually
     * right and the figure is usually one line to fix. It is a reason to put
     * the two texts side by side and say which number to look at.
     */
    public function hasInventedAmounts(): bool
    {
        return $this->unsupportedAmounts !== [];
    }

    /**
     * A refinement with no narrative is not a refinement.
     *
     * The grammar can force the key to be there; it cannot force a paragraph
     * into it. The caller is what turns this into an error, because an empty
     * answer means the same as no answer and must never reach the screen as a
     * replacement for what the client wrote.
     */
    public function isWritten(): bool
    {
        return $this->facts !== '';
    }

    /**
     * @return list<string>
     */
    private static function amountsMissingFrom(string $refined, string $facts): array
    {
        $written = self::amountsIn($facts);

        return array_values(array_filter(
            self::amountsIn($refined),
            static fn (string $amount): bool => ! in_array($amount, $written, true),
        ));
    }

    /**
     * Every figure the text writes with a "R$" in front of it, in the notation
     * the `amount` column speaks.
     *
     * Read through the parser the requirement agent's answers already go
     * through, so both sides of the comparison speak one dialect: "R$ 4.800,00"
     * in either text arrives here as "4800.00".
     *
     * @return list<string>
     */
    private static function amountsIn(string $text): array
    {
        preg_match_all('/R\$\s*(\d[\d.,]*\d|\d)/u', $text, $matches);

        return array_values(array_unique(array_filter(array_map(
            static fn (string $figure): ?string => RequirementData::amountWrittenAs($figure),
            $matches[1],
        ))));
    }

    private static function nullify(mixed $value): ?string
    {
        $trimmed = trim(is_string($value) ? $value : '');

        return $trimmed === '' ? null : $trimmed;
    }
}
