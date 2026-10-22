<?php

declare(strict_types=1);

namespace App\Domain\Doctrines\Support;

use App\Domain\CourtDecisions\Support\CourtDecisionSources;

/**
 * Which LexML records count as doctrine.
 *
 * The same portal as the case law — CourtDecisionSources owns the domain test,
 * and this class does not repeat it — but a narrower path. Scholarship lives in
 * the Rede Virtual de Bibliotecas, whose records are named
 * `urn:lex:br:rede.virtual.bibliotecas:<kind>:<year>;<id>`, and the kind is
 * what separates a work from the shelf it sits on:
 *
 * | kind | what it is | doctrine? |
 * |---|---|---|
 * | `livro`, `artigo.revista`, `capitulo.livro`, `folheto`… | a text somebody wrote | yes |
 * | `revista` | the periodical itself, every issue of it | **no** |
 *
 * A journal's own record has a publisher and no author, and citing "Revista
 * forense" as doctrine is citing a library shelf. It is the first thing a
 * search on `site:lexml.gov.br/urn` returns — measured on the first probe, where
 * six of ten results were periodicals —, so it is refused here rather than
 * hoped away in the prompt.
 *
 * ## Why `/busca/` is nowhere near this either
 *
 * The search screen is the obvious way to find doctrine — `busca/search?keyword=…
 * &f1-tipoDocumento=Doutrina` — and it is shut, measured on 2026-10-22: the
 * robots.txt has `Disallow: /busca/`, and after one request that got through,
 * every GET came back as the Senado's JavaScript proof-of-work challenge. The
 * screen still shows the lawyer that link, built from the terms the agent
 * searched, because a browser solves the challenge; this class is about what PHP
 * may *read*, and that is the `/urn/` record alone.
 */
final class DoctrineSources
{
    /**
     * The URN prefix every record of the library network carries.
     */
    private const LIBRARY = '/urn/urn:lex:br:rede.virtual.bibliotecas:';

    /**
     * Whether a url is the LexML record of one work of scholarship.
     */
    public static function isRecord(?string $url): bool
    {
        if (! CourtDecisionSources::covers($url)) {
            return false;
        }

        $path = strtolower(rawurldecode((string) parse_url(trim((string) $url), PHP_URL_PATH)));

        return str_starts_with($path, self::LIBRARY)
            && ! str_starts_with($path, self::LIBRARY.'revista:');
    }

    /**
     * Whether a url is the LexML record of a periodical — not doctrine itself,
     * but the index of every article it published.
     *
     * The search returns these more than anything else, and the page lists each
     * article with a link to the article's own record. DoctrineRecordReader::articlesIn()
     * reads that list, which is how a work the agent named by title is found
     * when the search returned only the journal it appeared in.
     */
    public static function isPeriodical(?string $url): bool
    {
        if (! CourtDecisionSources::covers($url)) {
            return false;
        }

        $path = strtolower(rawurldecode((string) parse_url(trim((string) $url), PHP_URL_PATH)));

        return str_starts_with($path, self::LIBRARY.'revista:');
    }

    /**
     * The URN of a record, read off its address.
     *
     * The doctrine page prints no "Nome Uniforme", unlike a ruling's, but the
     * address *is* the URN under `/urn/` — reading it from there is copying,
     * not composing. Null for anything that is not a record.
     */
    public static function urnOf(?string $url): ?string
    {
        if (! self::isRecord($url)) {
            return null;
        }

        $path = rawurldecode((string) parse_url(trim((string) $url), PHP_URL_PATH));

        return substr($path, strlen('/urn/'));
    }
}
