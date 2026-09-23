<?php

declare(strict_types=1);

namespace App\Domain\LegalPleadings\Support;

/**
 * The stored draft as the sequence of paragraphs it is, for the exported files.
 *
 * **A twin of `blocksOf()` in `pleading-document.tsx`, on purpose.** The screen
 * reads the text in the browser and the PDF and the DOCX are written here, and
 * the one thing that has to agree between them is which paragraph is a long
 * citation — the NBR 10520 indent is per block, and a document that is recued on
 * screen and flush in the file would be two documents. The rule is short enough
 * that a shared implementation would cost more than it saves; what keeps the two
 * honest is that both are pinned by tests on the same examples.
 *
 * A paragraph is what a blank line separates. The line breaks inside one stay,
 * because the numbered requests and the signature block are one paragraph each
 * with several lines. A paragraph is a citation when every line starts with `>`
 * (the marker is removed, it is editing grammar) or when it is wholly quoted and
 * longer than three lines' worth of characters.
 */
final class PleadingBlocks
{
    /** O marcador de citação, e o espaço opcional depois dele. */
    private const string CITATION_MARKER = '/^ {0,3}> ?/u';

    /** O bloco todo entre aspas — as retas e as tipográficas, não a apóstrofe. */
    private const string QUOTED_BLOCK = '/^["“][\s\S]+["”][.,;:]?$/u';

    /** Três linhas de Times 12pt numa coluna de 16 cm — ver LONG_CITATION_CHARS na tela. */
    private const int LONG_CITATION_CHARS = 270;

    /**
     * @return list<array{lines: list<string>, citation: bool}>
     */
    public static function of(string $content): array
    {
        $paragraphs = preg_split('/\n[ \t]*\n/', str_replace("\r\n", "\n", $content)) ?: [];

        $blocks = [];

        foreach ($paragraphs as $paragraph) {
            if (trim($paragraph) !== '') {
                $blocks[] = self::block($paragraph);
            }
        }

        return $blocks;
    }

    /**
     * @return array{lines: list<string>, citation: bool}
     */
    private static function block(string $paragraph): array
    {
        $lines = explode("\n", $paragraph);
        $marked = array_all($lines, static fn (string $line): bool => preg_match(self::CITATION_MARKER, $line) === 1);

        if ($marked) {
            $lines = array_map(static fn (string $line): string => (string) preg_replace(self::CITATION_MARKER, '', $line), $lines);
        }

        $text = implode("\n", $lines);

        return [
            'lines' => $lines,
            'citation' => $marked
                || (mb_strlen($text) > self::LONG_CITATION_CHARS && preg_match(self::QUOTED_BLOCK, trim($text)) === 1),
        ];
    }
}
