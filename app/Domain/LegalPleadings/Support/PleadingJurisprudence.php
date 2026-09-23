<?php

declare(strict_types=1);

namespace App\Domain\LegalPleadings\Support;

use App\Domain\CourtDecisions\Models\CourtDecision;
use Illuminate\Support\Collection;

/**
 * The seventh step's rulings, as the drafted document quotes them.
 *
 * **The agent decides where a ruling goes, and never writes one.** The dossier
 * hands PleadingDraftingAgent each ruling the lawyer kept under a marker —
 * `[[JULGADO 1]]` — and the agent's part is to put that marker, alone in its
 * paragraph, after a sentence that introduces it at the end of the thesis it
 * corroborates. What stands in the marker's place afterwards is composed here,
 * from the columns the LexML record filled.
 *
 * Two reasons, and both are the letterhead's argument applied to a quotation:
 *
 * - **An ementa is copy, not composition.** A model asked to transcribe two
 *   thousand characters of a ruling paraphrases, trims and — worst — fills in
 *   from memory, and a rewritten ementa is shaped exactly like a real one. The
 *   seventh step exists to refuse that failure; letting it back in at the last
 *   step would undo the guard at the moment the text becomes a filing.
 * - **The indent is structure, not style.** The NBR 10520 indent is the `>`
 *   paragraph that `blocksOf()` and PleadingBlocks both read, on screen and in
 *   the PDF and the DOCX. Written here, every quoted ruling is two marked
 *   paragraphs, whatever the model thinks a quotation looks like.
 *
 * The block is the one in the reference document the lawyers drew the section
 * from: the ementa between quotation marks, then the reference in parentheses,
 * both indented. The reference goes as far as the record does — the LexML page
 * prints no rapporteur and no DJe, so it stops at the órgão julgador and the
 * date of the judgment rather than inventing either.
 *
 * A marker is expanded only when it stands alone on its line. One the agent
 * wrote mid-sentence, or one naming a ruling the dossier does not carry, stays
 * as it is: double brackets the lawyer sees and PleadingDraftData counts as a
 * gap, which is the honest reading of both — something is missing there.
 */
final class PleadingJurisprudence
{
    /**
     * O marcador sozinho na linha. Tolera o que um modelo varia sem mudar o
     * sentido — caixa, espaços e um `>` de citação na frente —, e nada além.
     */
    private const string MARKER = '/^[ \t]*(?:>[ \t]*)?\[\[[ \t]*JULGADO[ \t]+(\d+)[ \t]*\]\][ \t]*$/imu';

    /**
     * Onde a ementa acaba e começa a certidão do julgamento.
     *
     * O campo Ementa do LexML traz as duas coisas coladas — "... Agravo interno
     * não provido. Decisão Vistos e relatados estes autos ..." —, às vezes com
     * o til perdido no caminho ("Decis?o"). Uma petição cita a ementa; a
     * certidão, com a lista dos ministros que votaram, dobraria a citação sem
     * acrescentar nada que se argumente.
     */
    private const string JUDGMENT_RECORD = '/\s+Decis(?:ão|\?o)\s+Vistos\b.*$/su';

    /**
     * "[[JULGADO 2]]" for the second ruling of the list.
     *
     * The position is the whole key, counted from one: the dossier numbers the
     * rulings in the order the relation returns them and `expand()` reads them
     * back in the same order, so both sides must be handed the same list.
     */
    public static function marker(int $position): string
    {
        return "[[JULGADO {$position}]]";
    }

    /**
     * The agent's text, with each marker replaced by the quotation it stands for.
     *
     * The block takes a blank line on each side whatever the agent left around
     * the marker, and the blank lines are then folded back to one, so a marker
     * written flush against its introducing sentence still lands in a paragraph
     * of its own — which is what the indent is applied to.
     *
     * @param  Collection<int, CourtDecision>  $decisions  the list the dossier numbered
     */
    public static function expand(string $content, Collection $decisions): string
    {
        $blocks = $decisions->values()
            ->map(static fn (CourtDecision $decision): string => self::block($decision))
            ->all();

        $expanded = preg_replace_callback(
            self::MARKER,
            static fn (array $match): string => isset($blocks[(int) $match[1] - 1])
                ? "\n".$blocks[(int) $match[1] - 1]."\n"
                : $match[0],
            $content,
        );

        return (string) preg_replace('/\n[ \t]*\n(?:[ \t]*\n)+/', "\n\n", (string) $expanded);
    }

    /**
     * The two indented paragraphs that stand where the marker was.
     *
     * Each is marked with `>` on its single line: the ementa is flattened to
     * one line by `ementa()`, so a line break inside it can never leave half a
     * paragraph unmarked and flush with the margin.
     */
    public static function block(CourtDecision $decision): string
    {
        return '> "'.self::ementa($decision->summary).'"'
            ."\n\n"
            .'> ('.self::reference($decision).')';
    }

    /**
     * "BRASIL. Superior Tribunal de Justiça. REsp 143513 / SP. 6ª Turma, julgado em 28/04/1998."
     *
     * The jurisdiction in capitals, the court, the case as the catalogue titles
     * it, then the órgão julgador and the date. The Autoridade column holds the
     * court and the órgão in one string separated by a full stop — "Superior
     * Tribunal de Justiça. 6ª Turma" — which is where it is split. The title is
     * copied as the record writes it, "REsp 143513 / SP" and all: normalising
     * it would make the reference harder to find on the page it came from.
     */
    public static function reference(CourtDecision $decision): string
    {
        [$court, $panel] = array_pad(explode('. ', (string) $decision->authority, 2), 2, null);

        $judged = implode(', ', self::present([
            $panel,
            $decision->decided_at === null ? null : 'julgado em '.$decision->decided_at->format('d/m/Y'),
        ]));

        $parts = self::present([
            $decision->locality === null ? null : mb_strtoupper($decision->locality),
            $court,
            $decision->title,
            $judged,
        ]);

        return implode('. ', array_map(static fn (string $part): string => rtrim($part, '.'), $parts)).'.';
    }

    /**
     * The ementa as the petição quotes it: without the judgment record, on one line.
     *
     * Public because the dossier sends the agent this same text — the model
     * decides where a ruling goes by reading exactly what will be quoted there.
     */
    public static function ementa(string $summary): string
    {
        $ementa = (string) preg_replace(self::JUDGMENT_RECORD, '', $summary);

        return trim((string) preg_replace('/\s+/u', ' ', $ementa));
    }

    /**
     * @param  list<string|null>  $parts
     * @return list<string>
     */
    private static function present(array $parts): array
    {
        return array_values(array_filter(
            array_map(static fn (?string $part): string => trim((string) $part), $parts),
            static fn (string $part): bool => $part !== '',
        ));
    }
}
