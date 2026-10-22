<?php

declare(strict_types=1);

namespace App\Domain\Doctrines\Support;

use App\Domain\Doctrines\Data\DoctrineData;
use App\Domain\Shared\Support\LexmlPage;

/**
 * Reads one doctrine record from the LexML and returns what the page says.
 *
 * LexmlRecordReader's sibling, and it exists for the same reason: **the model
 * finds; PHP transcribes.** Author, title, kind, year and the bibliographic
 * reference are what a pleading prints when it cites doctrine, and every one of
 * them is a field a model can get plausibly wrong — the right author with the
 * wrong book, the right book with a year it never had. Read from the record,
 * they are bounded by what the library network catalogued.
 *
 * ## What the page carries
 *
 * Measured on two records on 2026-10-22, an article and a book. The labelled
 * rows LexmlPage reads give Tipo, Autor, Título, Data and, when the library
 * wrote one, Ementa — usually a table of contents ("Sumário: …") rather than an
 * abstract. The rest of the reference lives in the "Publicação" panel, which is
 * not labelled rows but a block per edition or issue:
 *
 *     <b><a href="/urn/…:revista:2011;000913314">Revista Magister de direito do trabalho</a></b>
 *     Imprenta: Porto Alegre, Magister, 2011.
 *     Referência: v. 11, n. 64, p. 108–130, jan./fev., 2015.
 *
 * `publication()` reads the **first** block — the periodical and the pages of an
 * article, the publisher of a book — and composes the line. There is no
 * "Nome Uniforme" on these pages; the URN is the address itself, so it is read
 * off the url by DoctrineSources.
 *
 * ## What it refuses
 *
 * A url that is not a library record (`DoctrineSources::isRecord()`), before any
 * request. After the request, a page that does not answer, the challenge, and a
 * work with no author: `DoctrineData::isAttributable()` is the guard the whole
 * tab rests on, and a record that fails it is dropped and reported by the
 * caller rather than kept with an empty byline.
 */
final class DoctrineRecordReader
{
    /**
     * Where the relative `/urn/` links of a periodical's index point.
     */
    private const ORIGIN = 'https://www.lexml.gov.br';

    /**
     * The labelled rows that become columns. Autor is read apart — see `authors()`.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'Tipo' => 'kind',
        'Título' => 'title',
        'Data' => 'published_in',
        'Ementa' => 'summary',
        'Assuntos' => 'subject',
    ];

    /**
     * The record at that address, or null if it could not be read or attributed.
     */
    public function read(string $url): ?DoctrineData
    {
        if (! DoctrineSources::isRecord($url)) {
            return null;
        }

        $html = LexmlPage::fetch($url);

        if ($html === null) {
            return null;
        }

        $fields = LexmlPage::fields($html, self::LABELS);

        $doctrine = DoctrineData::fromArray([
            ...$fields,
            'author' => $this->authors($html),
            'summary' => $this->summary($fields['summary'] ?? null),
            'publication' => $this->publication($html, $fields['title'] ?? ''),
            'source_url' => $url,
            'urn' => DoctrineSources::urnOf($url),
        ]);

        return $doctrine->isAttributable() ? $doctrine : null;
    }

    /**
     * The articles a periodical's record lists, as index entries.
     *
     * Each one is `» <a href="/urn/…:artigo.revista:…">Título</a> / Autor.` —
     * a title, the authors in natural order, and the address of the article's
     * own record. These are **entries, not records**: they confirm that the
     * work exists and where, and DoctrineResearchData reads the address before
     * anything is shown. A page that is not a periodical, or does not answer,
     * lists nothing.
     *
     * @return list<DoctrineData>
     */
    public function articlesIn(string $url): array
    {
        if (! DoctrineSources::isPeriodical($url)) {
            return [];
        }

        $html = LexmlPage::fetch($url);

        if ($html === null) {
            return [];
        }

        $pattern = '#<a href="(/urn/urn:lex:br:rede\.virtual\.bibliotecas:artigo\.revista:[^"]+)">(.*?)</a>\s*/\s*(.*?)<br#su';

        if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        return array_map(static fn (array $match): DoctrineData => DoctrineData::fromArray([
            'title' => LexmlPage::text($match[2]),
            'author' => rtrim(LexmlPage::text($match[3]), '. '),
            'source_url' => self::ORIGIN.html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ]), $matches);
    }

    /**
     * Every author the record names, in its order, joined.
     *
     * A co-written work prints one Autor row per author, and keeping only the
     * first would misattribute the work to one of them. The catalogue's own form
     * ("Theodoro Júnior, Humberto, 1938") is kept as it is: it is the heading a
     * library searches by, and reformatting it is composing.
     */
    private function authors(string $html): string
    {
        $authors = [];

        foreach (LexmlPage::rows($html) as [$label, $value]) {
            if ($label === 'Autor' && $value !== '') {
                $authors[] = $value;
            }
        }

        return implode('; ', array_values(array_unique($authors)));
    }

    /**
     * The Ementa row, with its leading label spaced.
     *
     * The page writes `<b>Sumário:</b>Os danos…`, and stripping the tags glues
     * the label to the text. Only the leading label is touched.
     */
    private function summary(?string $summary): ?string
    {
        if ($summary === null) {
            return null;
        }

        return preg_replace('/^(\p{L}+):(?=\S)/u', '$1: ', $summary) ?? $summary;
    }

    /**
     * Where the text is: the first block of the "Publicação" panel, as one line.
     *
     * The parent work, the imprint and the reference, each as the record prints
     * them, joined by full stops. The parent work is dropped when it is the
     * record's own title — a book's edition block names the book again —, and a
     * reference that is only a year is dropped too, since the imprint already
     * carries it.
     */
    private function publication(string $html, string $title): ?string
    {
        $block = $this->firstPublication($html);

        if ($block === null) {
            return null;
        }

        $work = $this->match('#<b>\s*<a[^>]*href="/urn/[^"]*"[^>]*>(.*?)</a>#su', $block);

        $parts = array_filter([
            $work !== null && ! $this->sameWork($work, $title) ? $work : null,
            $this->match('#Imprenta:\s*(.*?)<br#su', $block),
            $this->reference($block),
        ]);

        if ($parts === []) {
            return null;
        }

        return implode('. ', array_map(static fn (string $part): string => rtrim($part, '. '), $parts)).'.';
    }

    /**
     * The markup of the first edition or issue under "Publicação", or null.
     */
    private function firstPublication(string $html): ?string
    {
        $start = strpos($html, 'Publicação:');

        if ($start === false) {
            return null;
        }

        $panel = substr($html, $start);
        $first = strpos($panel, 'list-group-item');

        if ($first === false) {
            return null;
        }

        $second = strpos($panel, 'list-group-item', $first + 1);

        return $second === false ? $panel : substr($panel, 0, $second);
    }

    private function reference(string $block): ?string
    {
        $reference = $this->match('#Referência:\s*(.*?)<br#su', $block);

        return $reference === null || preg_match('/^\d{4}\.?$/', $reference) === 1 ? null : $reference;
    }

    private function sameWork(string $work, string $title): bool
    {
        return $title !== '' && str_starts_with(mb_strtolower($work), mb_strtolower($title));
    }

    private function match(string $pattern, string $html): ?string
    {
        if (preg_match($pattern, $html, $match) !== 1) {
            return null;
        }

        $text = LexmlPage::text($match[1]);

        return $text === '' ? null : $text;
    }
}
