<?php

declare(strict_types=1);

namespace App\Domain\CourtDecisions\Support;

use App\Domain\CourtDecisions\Data\CourtDecisionData;
use Illuminate\Support\Facades\Http;
use Throwable;

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
 */
final class LexmlRecordReader
{
    /**
     * Short on purpose.
     *
     * Several records are read per research run, in series, inside a request a
     * browser is already waiting on — and the expensive part of that wait is
     * the two inferences ahead of this. A record that does not answer in ten
     * seconds is dropped, which costs one ruling; waiting on each of six would
     * cost the step.
     */
    private const TIMEOUT = 10;

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

        $html = $this->fetch($url);

        if ($html === null) {
            return null;
        }

        $fields = $this->parse($html);

        if ($fields === []) {
            return null;
        }

        $decision = CourtDecisionData::fromArray([...$fields, 'source_url' => $url]);

        return $decision->isWritten() ? $decision : null;
    }

    /**
     * The page, or null for anything that is not a page.
     *
     * A browser's User-Agent, because the portal sits behind a filter that
     * answers a bare client with a challenge; on `/urn/` it does not fire, and
     * asking politely is what keeps it that way. Every failure — DNS, timeout,
     * 404, a 200 carrying the challenge instead of the record — folds into
     * null, because the caller does the same thing with all of them.
     */
    private function fetch(string $url): ?string
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; LexIA/1.0)'])
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $html = $response->body();

        // A página de desafio responde 200 e não é um registro. Reconhecê-la
        // pelo título é mais barato e mais estável que pelo corpo, que é
        // JavaScript.
        return str_contains($html, 'Verificação de segurança') ? null : $html;
    }

    /**
     * The labelled fields of the record, as the page prints them.
     *
     * The markup is a Bootstrap list whose every row is a `<strong>` label
     * followed by the value in the next cell, so one expression reads them all.
     * A label the page omits — Assuntos, often — simply does not match, and the
     * key stays absent, which `CourtDecisionData::fromArray()` already reads as
     * null.
     *
     * @return array<string, string>
     */
    private function parse(string $html): array
    {
        $pattern = '#<strong>\s*([^<]+?)\s*</strong>\s*</div>\s*'
            .'<div[^>]*class="[^"]*text-left[^"]*"[^>]*>(.*?)</div>#su';

        if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $fields = [];

        foreach ($matches as $match) {
            $label = $this->text($match[1]);
            $column = self::LABELS[$label] ?? null;

            if ($column !== null && ! isset($fields[$column])) {
                $fields[$column] = $this->text($match[2]);
            }
        }

        return array_filter($fields, static fn (string $value): bool => $value !== '');
    }

    /**
     * One cell as plain text.
     *
     * The portal writes `&#150;` for the en dash its ementas are full of, so
     * decoding runs before the tags come out. `menos [x]` is the collapse link
     * the Assuntos row carries, and it is markup pretending to be content.
     */
    private function text(string $html): string
    {
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stripped = strip_tags(preg_replace('#<br\s*/?>#i', ' ', $decoded) ?? $decoded);
        $collapsed = preg_replace('/\s+/u', ' ', $stripped) ?? $stripped;

        return trim(str_replace(['menos [x]', 'mais [+]'], '', $collapsed));
    }
}
