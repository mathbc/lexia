<?php

declare(strict_types=1);

namespace App\Domain\CourtDecisions\Support;

/**
 * The portal a court decision is allowed to have been read from.
 *
 * The sibling of OfficialLegalSources, and a separate class rather than a new
 * line in it. That is the decision worth explaining, because merging them is
 * the obvious move and it would be wrong in a direction nobody would notice.
 *
 * OfficialLegalSources answers "where may a *norm* be confirmed?" — the
 * Planalto for the text of an article, the STJ for the wording of a súmula. The
 * LexML is not that: it is a **catalogue of documents**, run by the Senado,
 * whose record of an acórdão carries the ementa the court published and a URN
 * that names it canonically. Excellent for finding a ruling; not the place to
 * confirm that Súmula 393 still says what it said. Adding `lexml.gov.br` to
 * that list would have widened the thesis research of step six — silently, and
 * without anybody asking for it.
 *
 * ## Why `/busca/` is nowhere near this agent
 *
 * Measured against the live portal, and it decides the shape of the prompt:
 *
 * | path | robots.txt | a client asking for it |
 * |---|---|---|
 * | `/busca/` and `/busca/SRU` | `Disallow` | the Senado's anti-bot challenge |
 * | `/urn/<a urn>` | `Allow` | 200, the record in full |
 *
 * So the search screen and the SRU endpoint are both out: they are not in any
 * index for a search tool to find, and a fetch of one comes back as a page of
 * JavaScript proof-of-work rather than as data. What is left is exactly what is
 * wanted — the per-document page, which Google does index and which answers a
 * plain GET with Localidade, Autoridade, Título, Data, Ementa and the URN.
 *
 * Membership is by registrable domain, with the leading-dot subdomain test
 * OfficialLegalSources explains: plain `str_ends_with()` would also admit the
 * cousin `naoelexml.gov.br`.
 */
final class CourtDecisionSources
{
    /**
     * @var list<string>
     */
    public const DOMAINS = [
        // O portal do LexML, mantido pelo Senado: o catálogo onde o acórdão de
        // um tribunal tem uma página própria, com ementa e URN.
        'lexml.gov.br',
    ];

    /**
     * Whether a url was served by the portal above.
     *
     * Anything that is not a parseable absolute url with a host is false, which
     * folds the same three failures OfficialLegalSources folds: the model that
     * wrote the sentinel into the url field, the one that wrote a bare "LexML",
     * and the one that invented a Jusbrasil address are all citations nobody
     * can check.
     */
    public static function covers(?string $url): bool
    {
        $host = is_string($url) ? parse_url(trim($url), PHP_URL_HOST) : null;

        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower(rtrim($host, '.'));

        foreach (self::DOMAINS as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a url is the page of one document rather than something else on
     * the portal.
     *
     * The stricter half of the guard, and the reason it is not folded into
     * `covers()`: `https://www.lexml.gov.br/` passes the domain test and
     * confirms nothing, exactly as a court's front page does in step six. A
     * decision is only cited from the record that carries its ementa.
     */
    public static function isRecord(?string $url): bool
    {
        return self::covers($url) && str_contains(strtolower((string) $url), '/urn/');
    }

    /**
     * The list as the prompt prints it, one domain per line.
     */
    public static function asPromptList(): string
    {
        return implode(PHP_EOL, array_map(
            static fn (string $domain): string => '- '.$domain,
            self::DOMAINS,
        ));
    }
}
