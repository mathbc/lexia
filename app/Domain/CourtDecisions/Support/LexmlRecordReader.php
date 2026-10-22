<?php

declare(strict_types=1);

namespace App\Domain\CourtDecisions\Support;

use App\Domain\CourtDecisions\Data\CourtDecisionData;
use App\Domain\Shared\Support\LexmlPage;

/**
 * Reads one LexML record and returns what the page actually says.
 *
 * **This class exists because the model may not be the source of an ementa, and
 * that is a provider fact rather than a preference.** Measured against
 * `gemini-3.6-flash`, with `google_search` as the only tool and the same prompt
 * in both runs except for one paragraph:
 *
 * | what the prompt asks of the ementa | `finishReason` | answer |
 * |---|---|---|
 * | "copie, palavra por palavra" | **`RECITATION`** | zero parts, empty string |
 * | "escreva com suas próprias palavras" | `STOP` | the full sheet, 4.4 KB |
 *
 * Asking Gemini to reproduce a published headnote verbatim trips its recitation
 * filter, and the filter does not trim the quotation — it discards the whole
 * candidate. The request still returns 200 with live grounding citations, and
 * `text` is empty, which downstream is indistinguishable from an outage.
 *
 * The way out is not a softer prompt, because the softened answer is the
 * failure wearing the costume of success: it comes back with real rulings at
 * real addresses whose ementas have been quietly abridged. Measured on `AgInt
 * no REsp 2107831/RS`, the model dropped two segments of the headnote and
 * compressed a numbered item — a paragraph that reads like the court's text and
 * is not. A pleading cites the ementa; a paraphrase presented as one is the
 * same class of error as an invented súmula, and harder to spot.
 *
 * So the division of labour is the one the project already makes whenever a
 * rule cannot live in a prompt — the money guard, the signature, the age. **The
 * model finds; PHP transcribes.** What comes back from here is bounded by what
 * the Senado published, not by what a model remembered, and no filter stands
 * between the two.
 *
 * ## Why an HTTP client works here and not on the search
 *
 * Because of where the portal draws its line, which is the opposite of the
 * obvious guess:
 *
 * | path | robots.txt | a plain GET |
 * |---|---|---|
 * | `/busca/`, `/busca/SRU` | `Disallow` | the Senado's anti-bot challenge, in JS |
 * | `/urn/<a urn>` | `Allow` | 200, the record in full |
 *
 * The search screen and the SRU API are shut — which is why finding a ruling
 * needs a grounded model at all, and why this class cannot simply query the
 * catalogue and skip the inference. The document page is open, and open is all
 * this needs.
 *
 * ## What it refuses
 *
 * A url that is not a LexML `/urn/` record, in the same breath as the guard in
 * CourtDecisionResearchData: this class is reached with an address a model
 * wrote, and fetching whatever it wrote would be the hole the guard exists to
 * close. Everything else — a timeout, a 404, a page whose labels moved — comes
 * back as `null`, and the ruling is dropped and reported rather than kept with
 * a field nobody read.
 *
 * The fetch and the reading of the labelled rows are LexmlPage's, shared with
 * DoctrineRecordReader: the two pages are one template. What is this class's
 * own is which labels a ruling has and what they become.
 */
final class LexmlRecordReader
{
    /**
     * The labels the page prints, mapped to the columns they fill.
     *
     * "Nome Uniforme" is the URN, and it is the one label whose name gives no
     * hint of that.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'Localidade' => 'locality',
        'Autoridade' => 'authority',
        'Título' => 'title',
        'Data' => 'decided_at',
        'Ementa' => 'summary',
        'Assuntos' => 'subject',
        'Nome Uniforme' => 'urn',
    ];

    /**
     * The record at that address, or null if it could not be read.
     */
    public function read(string $url): ?CourtDecisionData
    {
        if (! CourtDecisionSources::isRecord($url)) {
            return null;
        }

        $html = LexmlPage::fetch($url);

        if ($html === null) {
            return null;
        }

        $fields = LexmlPage::fields($html, self::LABELS);

        if ($fields === []) {
            return null;
        }

        $decision = CourtDecisionData::fromArray([...$fields, 'source_url' => $url]);

        return $decision->isWritten() ? $decision : null;
    }
}
