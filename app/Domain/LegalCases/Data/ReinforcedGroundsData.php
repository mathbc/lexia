<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Data;

use App\Domain\LegalPleadings\Support\PleadingJurisprudence;

/**
 * The rewritten DO DIREITO, and the three readings that decide whether it may
 * replace the one the drafting agent wrote.
 *
 * The sibling of PleadingDraftData, with one difference that changes what the
 * guards do. PleadingDraftData *reports*: its document is the only one there is,
 * and a sentence cannot be left half-blank. Here there is always a fallback — the
 * section as the drafting agent wrote it, which already went through the same
 * rules — so a reading that fails does not point at a line, it **refuses the
 * rewrite**. ReinforcePleadingGrounds turns the refusal into an exception, and
 * DraftLegalPleading keeps the unreinforced section.
 *
 * Three readings, each against a failure a rewrite of the argument invites:
 *
 * - **The markers.** Every `[[JULGADO n]]` alone on its line before must be
 *   alone on its line after, the same number of times. A marker dropped is a
 *   ruling the lawyer chose that would never be quoted; a marker repeated is a
 *   ruling quoted twice. Neither would raise an error anywhere else.
 * - **The money.** The same reader as PleadingDraftData, against the dossier,
 *   the narrative and the section itself.
 * - **The precedents.** "Strengthen the argument" is exactly the instruction
 *   that makes a model reach for a súmula or a tema it remembers — real or not,
 *   it is not one the lawyer chose. A súmula or tema number the sources do not
 *   write refuses the rewrite. Articles of law are left to the instructions:
 *   they are what an argument is made of, and the dossier's theses list the
 *   ones to cite.
 */
final readonly class ReinforcedGroundsData
{
    private const string PRECEDENT = '/\b(s[úu]mulas?(?:\s+vinculantes?)?|temas?)\s+(?:n(?:[º°o]|\.)\s*)?(\d[\d.]*\d|\d)/iu';

    /**
     * @param  list<string>  $unsupportedAmounts  figures the rewrite names and no source writes
     * @param  list<string>  $unsupportedCitations  súmulas and temas the rewrite cites and no source writes
     */
    public function __construct(
        public string $content,
        public bool $markersKept,
        public array $unsupportedAmounts = [],
        public array $unsupportedCitations = [],
    ) {}

    /**
     * @param  array<string, mixed>  $answer
     * @param  string  $original  the body of DO DIREITO the agent was given
     * @param  string  $sources  the dossier and the narrative, as the caller joined them
     */
    public static function fromAgent(array $answer, string $original, string $sources): self
    {
        $content = trim(is_string($answer['content'] ?? null) ? $answer['content'] : '');
        $authorised = $original.' '.$sources;

        return new self(
            content: $content,
            markersKept: PleadingJurisprudence::markersIn($content) === PleadingJurisprudence::markersIn($original),
            unsupportedAmounts: PleadingDraftData::amountsMissingFrom($content, $authorised),
            unsupportedCitations: array_values(array_diff_key(self::precedentsIn($content), self::precedentsIn($authorised))),
        );
    }

    /**
     * Why this rewrite may not replace the original, or null when it may.
     */
    public function refusal(): ?string
    {
        return match (true) {
            $this->content === '' => 'O agente de embasamento devolveu uma seção vazia.',
            ! $this->markersKept => 'O agente de embasamento perdeu, repetiu ou inventou um marcador de julgado.',
            $this->unsupportedAmounts !== [] => 'O agente de embasamento escreveu cifras sem fonte: '.implode(', ', $this->unsupportedAmounts).'.',
            $this->unsupportedCitations !== [] => 'O agente de embasamento citou o que o dossiê não traz: '.implode(', ', $this->unsupportedCitations).'.',
            default => null,
        };
    }

    public function isAcceptable(): bool
    {
        return $this->refusal() === null;
    }

    /**
     * Every súmula and tema cited, keyed as "sumula 393" or "tema 1061" — lower
     * case, no accent and no thousands dot, so "Súmula nº 393" and "sumula 393"
     * are the same citation — and valued as the text first wrote it, which is
     * what the refusal reports.
     *
     * @return array<string, string>
     */
    private static function precedentsIn(string $text): array
    {
        preg_match_all(self::PRECEDENT, $text, $matches, PREG_SET_ORDER);

        $precedents = [];

        foreach ($matches as $match) {
            $precedents[self::kind($match[1]).' '.str_replace('.', '', $match[2])] ??= $match[0];
        }

        return $precedents;
    }

    /**
     * "Súmulas Vinculantes" as "sumula vinculante": the plural names the same
     * instrument, and the accent is the one a model drops.
     */
    private static function kind(string $kind): string
    {
        $lower = str_replace('ú', 'u', mb_strtolower($kind));

        return (string) preg_replace(['/\s+/u', '/s\b/u'], [' ', ''], $lower);
    }
}
