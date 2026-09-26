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
 * ## The abridged ementa, and why it is still copy
 *
 * A two-thousand-character ementa quoted whole buries the sentence the thesis
 * leans on, so the agent may also choose **which passages** are quoted. It
 * chooses them by number: `passages()` cuts the ementa into the heading and then
 * one passage per sentence or item, the dossier shows them numbered, and the
 * agent answers with the numbers. `abridge()` writes the chosen passages back
 * in the court's order and marks every cut with `[...]`, as the NBR 10520 marks
 * a suppression. A number that names no passage is ignored, and with none left
 * the ementa goes whole, which is what it did before any of this existed.
 *
 * Numbers and not text, for two reasons. The first is the argument above: the
 * model never writes a word of the ementa, so there is nothing to paraphrase
 * and nothing to check. The second was measured: asked to copy passages
 * verbatim, Gemini answered with **no text at all** — `finishReason:
 * RECITATION`, zero completion tokens, the whole pleading lost — because an
 * ementa is public text its filter recognises. A list of integers recites
 * nothing.
 *
 * Two things never depend on the model's choice. The **heading** — the block
 * capitals that open an ementa and say what the case is about — is kept by
 * `heading()` whether or not it was chosen. And the **reference** is composed
 * from the columns as before, so the title, the órgão and the date of the
 * judgment are in every quotation, abridged or not.
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
     * Onde o cabeçalho acaba: o primeiro item numerado ou marcado da ementa —
     * "1.", "- ", "I –" —, que é onde o tribunal começa a fundamentar.
     */
    private const string ITEM = '/^(?:\d+[.)]|[-–—•]|[IVXLC]+[ \t]*[-–—)])(?:\s|$)/u';

    /**
     * Onde um trecho acaba e começa o seguinte: depois de ponto, ponto e vírgula
     * ou parêntese, antes de maiúscula, aspas, travessão ou item numerado. Não
     * corta depois das abreviaturas que uma ementa usa — "art.", "n.", "Min." —,
     * nem entre o número do item e o texto dele ("1. O proprietário"), nem antes
     * de número que não é item ("art. 18", "8.078/90").
     */
    private const string BOUNDARY = '/(?<=[.;)])(?<!\bart\.|\barts\.|\bmin\.|\brel\.|\bdes\.|\bfls\.|\binc\.|\bcf\.|\bn\.|\bp\.|\s\d\.|\s\d\d\.|^\d\.|^\d\d\.)\s+(?=[\p{Lu}"“(\-–—•]|\d{1,2}[.)]\s)/iu';

    private const string ELISION = '[...]';

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
     * @param  array<int, list<int>>  $excerpts  the passage numbers the agent chose, keyed by the marker's number
     */
    public static function expand(string $content, Collection $decisions, array $excerpts = []): string
    {
        $blocks = $decisions->values()
            ->map(static fn (CourtDecision $decision, int $index): string => self::block($decision, $excerpts[$index + 1] ?? []))
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
     *
     * @param  list<int>  $passages  the numbers of the passages the agent chose; empty quotes the ementa whole
     */
    public static function block(CourtDecision $decision, array $passages = []): string
    {
        return '> "'.self::abridge(self::ementa($decision->summary), $passages).'"'
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
     * The ementa cut into the passages the dossier numbers: the heading first,
     * when there is one, then each sentence or item of the rest.
     *
     * Every cut falls on a single space of the flattened ementa, so the
     * passages joined by a space are the ementa again — which is what lets
     * `abridge()` compose a quotation out of them without a word that was not
     * in the record.
     *
     * @return list<string>
     */
    public static function passages(string $ementa): array
    {
        $heading = self::heading($ementa);
        $rest = $heading === '' ? $ementa : mb_substr($ementa, mb_strlen($heading) + 1);

        return array_values(array_filter(
            [$heading, ...(preg_split(self::BOUNDARY, $rest) ?: [])],
            static fn (string $passage): bool => $passage !== '',
        ));
    }

    /**
     * The ementa with only the chosen passages, `[...]` in every gap, and the
     * heading always among them.
     *
     * Nothing chosen, or nothing left out, and the ementa goes whole.
     *
     * @param  list<int>  $chosen  passage numbers, counted from one as the dossier shows them
     */
    public static function abridge(string $ementa, array $chosen): string
    {
        $passages = self::passages($ementa);
        $kept = array_filter($chosen, static fn (int $number): bool => isset($passages[$number - 1]));

        if ($kept === []) {
            return $ementa;
        }

        if (self::heading($ementa) !== '') {
            $kept[] = 1;
        }

        $kept = array_values(array_unique($kept));
        sort($kept);

        return self::joined($passages, $kept);
    }

    /**
     * "PROCESSUAL CIVIL. EMBARGOS À EXECUÇÃO. PENHORA." — the block capitals an
     * ementa opens with.
     *
     * The leading sentences with no lowercase letter, stopping at the first
     * numbered or dashed item, which is where the court starts reasoning. Empty
     * when there is no such opening, and also when it would be the whole ementa:
     * some courts write everything in capitals, and a heading that is the entire
     * text is no heading at all.
     */
    public static function heading(string $ementa): string
    {
        $sentences = preg_split('/(?<=\.)\s+/u', $ementa) ?: [];
        $heading = [];

        foreach ($sentences as $index => $sentence) {
            if (preg_match('/\p{Ll}/u', $sentence) === 1 || ($index > 0 && preg_match(self::ITEM, $sentence) === 1)) {
                break;
            }

            $heading[] = $sentence;
        }

        return count($heading) < count($sentences) ? implode(' ', $heading) : '';
    }

    /**
     * The number of every marker standing alone on its line, in ascending order.
     *
     * What ReinforcedGroundsData compares before and after the grounds are
     * rewritten: a marker lost is a ruling the lawyer chose that would silently
     * never be quoted, and a marker gained is one `expand()` would quote twice.
     *
     * @return list<int>
     */
    public static function markersIn(string $content): array
    {
        preg_match_all(self::MARKER, $content, $matches);

        $markers = array_map('intval', $matches[1]);
        sort($markers);

        return $markers;
    }

    /**
     * The kept passages in order, with `[...]` wherever one or more were left
     * out — between two kept ones, and after the last.
     *
     * @param  list<string>  $passages
     * @param  list<int>  $kept  sorted, unique, and every one a valid passage number
     */
    private static function joined(array $passages, array $kept): string
    {
        $parts = [];
        $previous = 0;

        foreach ($kept as $number) {
            if ($number > $previous + 1) {
                $parts[] = self::ELISION;
            }

            $parts[] = $passages[$number - 1];
            $previous = $number;
        }

        if ($previous < count($passages)) {
            $parts[] = self::ELISION;
        }

        return implode(' ', $parts);
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
