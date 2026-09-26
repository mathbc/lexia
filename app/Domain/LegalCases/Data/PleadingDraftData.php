<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Data;

use App\Domain\Requirements\Data\RequirementData;

/**
 * The drafted document, the gaps left in it, and the money in it that nobody
 * wrote.
 *
 * The sibling of RefinedFactsData, and the two guards are deliberately the same
 * shape: a value the model produced, plus two readings of it that the screen
 * needs in order to tell the lawyer where to look. Neither reading changes the
 * text. Prose cannot be corrected by deletion — the paragraph around a bad
 * figure is usually right, and dropping it would leave a hole in the middle of a
 * petição.
 *
 * ## The gaps, which are the expected answer
 *
 * `placeholders` is not a guard at all: it is a **report of the agent doing its
 * job**. A petição inicial qualifies the parties and names a comarca, and asks
 * for a case number the moment it is distributed by dependency — data the
 * registration either never had or was never obliged to fill in. The agent is
 * instructed to write `[estado civil]` rather than guess, so a draft arrives
 * with brackets in it wherever the dossier was silent. An empty list is not a
 * failure of this reading; it is the pleading whose every field happened to be
 * filled in, which is the rarer of the two.
 *
 * What the screen does with it is say "7 lacunas a preencher", which turns an
 * obligatory paragraph the lawyer would otherwise have to audit word by word
 * into a short list of fields to complete.
 *
 * ## The money, which is a guard
 *
 * `unsupportedAmounts` is the guard `RequirementListData::fromAgent()` and
 * `RefinedFactsData::fromAgent()` both run, in the form prose allows. The
 * measured failure repeats here with more room than anywhere else: a document
 * that numbers five requests with figures invites a closing line that adds them
 * up — "totalizando R$ 200.000,00" — and the total is arithmetic rather than
 * anything anyone claimed. Banning the connectives in the prompt holds it better
 * than banning the operation did, and still not always.
 *
 * What authorises a figure here is wider than in the facts agent, and it has to
 * be: the narrative is not the only source any more. A request the lawyer
 * already wrote with `amount` of 50000.00 is exactly as legitimate a source for
 * "R$ 50.000,00" in the value of the cause as the client's own account. So
 * `fromAgent()` takes both, concatenated by the caller.
 *
 * Only currency is checked, as in the sibling: bare numbers change shape
 * legitimately all the time, and article numbers are numbers.
 *
 * ## The excerpts, which are a choice and not a text
 *
 * `excerpts` carries the numbers of the passages of each ruling's ementa the
 * agent wants quoted, keyed by the number of the ruling's marker. Numbers, never
 * text: the dossier shows each ementa cut into numbered passages, and the
 * quotation is composed from the record by PleadingJurisprudence. They are read
 * here as loosely as the grammar allows a model to vary — a number that arrives
 * as a string, an entry with no numbers — and nothing more: whether a number
 * names a passage is decided there, against the ementa, when it abridges.
 */
final readonly class PleadingDraftData
{
    /**
     * @param  list<string>  $placeholders  the bracketed gaps left for the lawyer
     * @param  list<string>  $unsupportedAmounts  figures the document names and no source writes
     * @param  array<int, list<int>>  $excerpts  the passage numbers of each ementa to quote, keyed by marker number
     */
    public function __construct(
        public string $content,
        public array $placeholders = [],
        public array $unsupportedAmounts = [],
        public array $excerpts = [],
    ) {}

    /**
     * The drafting agent's answer, under the key its schema declares, with
     * everything that is allowed to authorise a figure in it.
     *
     * @param  array<string, mixed>  $answer
     * @param  string  $sources  the narrative and the requests, as the caller joined them
     */
    public static function fromAgent(array $answer, string $sources): self
    {
        $content = trim(is_string($answer['content'] ?? null) ? $answer['content'] : '');

        return new self(
            content: $content,
            placeholders: self::gapsIn($content),
            unsupportedAmounts: self::amountsMissingFrom($content, $sources),
            excerpts: self::excerptsIn($answer['excerpts'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'placeholders' => $this->placeholders,
            'unsupported_amounts' => $this->unsupportedAmounts,
            'excerpts' => $this->excerpts,
        ];
    }

    /**
     * A draft with no document is not a draft.
     *
     * The grammar guarantees the key, never the pages. The caller turns this
     * into an error, because an empty answer means the same as no answer and
     * must never be stored as a version.
     */
    public function isWritten(): bool
    {
        return $this->content !== '';
    }

    /**
     * Whether this draft names money that no source writes.
     *
     * Not a reason to throw it away — the sentence around the figure is usually
     * right and the figure is one line to fix. It is a reason to point at it.
     */
    public function hasInventedAmounts(): bool
    {
        return $this->unsupportedAmounts !== [];
    }

    /**
     * Whether there is anything left for the lawyer to fill in.
     */
    public function hasGaps(): bool
    {
        return $this->placeholders !== [];
    }

    /**
     * Every bracketed marker the document leaves, in the order it leaves them.
     *
     * Public because a saved draft is read back through it too — `LegalPleading`
     * delegates here rather than carrying a second copy of this pattern, and the
     * count the screen shows is therefore always about the text on screen, even
     * after the lawyer has filled half the gaps in by hand.
     *
     * Three things bound what counts, and each answers something real:
     *
     * - **At least one letter.** `[...]` is an elision in the prose, not a field
     *   to fill, and reporting it would tell the lawyer there is work where
     *   there is none.
     * - **No newline.** A bracket spanning a line break is a long quotation.
     * - **Eighty characters.** Beyond that it is a passage, not a field name.
     *
     * @return list<string>
     */
    public static function gapsIn(string $content): array
    {
        preg_match_all('/\[(?=[^\]\n]*\p{L})[^\]\n]{1,80}\]/u', $content, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * The figures `$content` names and `$sources` does not write.
     *
     * Public because ReinforcedGroundsData runs this same guard over the
     * rewritten DO DIREITO — one reader of money, whichever agent wrote it.
     *
     * @return list<string>
     */
    public static function amountsMissingFrom(string $content, string $sources): array
    {
        $written = self::amountsIn($sources);

        return array_values(array_filter(
            self::amountsIn($content),
            static fn (string $amount): bool => ! in_array($amount, $written, true),
        ));
    }

    /**
     * The `excerpts` key, keyed by the ruling's number, with every entry the
     * grammar let through but that says nothing dropped.
     *
     * @return array<int, list<int>>
     */
    private static function excerptsIn(mixed $excerpts): array
    {
        $read = [];

        foreach (is_array($excerpts) ? $excerpts : [] as $excerpt) {
            $excerpt = is_array($excerpt) ? $excerpt : [];
            $ruling = self::positive($excerpt['ruling'] ?? null);
            $passages = self::passagesIn($excerpt['passages'] ?? null);

            if ($ruling !== null && $passages !== []) {
                $read[$ruling] = [...$read[$ruling] ?? [], ...$passages];
            }
        }

        return $read;
    }

    /**
     * A number counted from one — also when it arrives as "2".
     */
    private static function positive(mixed $number): ?int
    {
        $read = filter_var($number, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $read === false ? null : $read;
    }

    /**
     * @return list<int>
     */
    private static function passagesIn(mixed $passages): array
    {
        return array_values(array_filter(array_map(
            self::positive(...),
            is_array($passages) ? $passages : [],
        )));
    }

    /**
     * Every figure written with a "R$" in front of it, in the notation the
     * `amount` column speaks.
     *
     * Read through the parser the requirement answers already go through, so
     * both sides of the comparison speak one dialect: "R$ 4.800,00" in either
     * text arrives here as "4800.00".
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
}
