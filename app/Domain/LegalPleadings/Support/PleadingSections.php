<?php

declare(strict_types=1);

namespace App\Domain\LegalPleadings\Support;

/**
 * The DO DIREITO section of a drafted pleading, cut out and put back.
 *
 * PleadingGroundsReinforcementAgent rewrites one section of the document and
 * nothing else, and this is where that section is found. What it hands the
 * agent is the **body** — everything between the `III – DO DIREITO` line and
 * the next numbered heading —, never the heading itself: the roman numeral is
 * sequential over the sections that exist, and it is not the second agent's to
 * renumber. `withGrounds()` puts the body back under the heading the drafting
 * agent wrote.
 *
 * A heading is a roman numeral, a dash and a title, which is the only form the
 * drafting instructions allow (`II – DOS FATOS`); the three dashes a model
 * alternates between are accepted. The thesis subtitles inside DO DIREITO carry
 * no numeral, so they never end the section.
 *
 * No next heading means no section: the body would run into the closing and the
 * requests, and a rewrite of those is exactly what must not happen. The caller
 * reads `null` as "nothing to reinforce" and keeps the draft as it is.
 */
final class PleadingSections
{
    private const string GROUNDS = '/^[ \t]*[IVXLC]+[ \t]*[–—-][ \t]*DO[ \t]+DIREITO[ \t]*$/mu';

    private const string HEADING = '/^[ \t]*[IVXLC]+[ \t]*[–—-][ \t]*\p{Lu}/mu';

    /**
     * The body of DO DIREITO, without its heading, or null when the document has
     * no such section followed by another.
     */
    public static function grounds(string $content): ?string
    {
        $bounds = self::bounds($content);

        return $bounds === null ? null : trim(substr($content, $bounds[0], $bounds[1] - $bounds[0]));
    }

    /**
     * The document with `$body` in place of the body of DO DIREITO, one blank
     * line on each side. A document without the section comes back untouched.
     */
    public static function withGrounds(string $content, string $body): string
    {
        $bounds = self::bounds($content);

        if ($bounds === null) {
            return $content;
        }

        return rtrim(substr($content, 0, $bounds[0]))
            ."\n\n".trim($body)."\n\n"
            .ltrim(substr($content, $bounds[1]));
    }

    /**
     * Byte offsets of the body: from the end of the DO DIREITO line to the start
     * of the next heading.
     *
     * @return array{int, int}|null
     */
    private static function bounds(string $content): ?array
    {
        if (preg_match(self::GROUNDS, $content, $heading, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $start = $heading[0][1] + strlen($heading[0][0]);

        if (preg_match(self::HEADING, $content, $next, PREG_OFFSET_CAPTURE, $start) !== 1) {
            return null;
        }

        return [$start, $next[0][1]];
    }
}
