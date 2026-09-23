<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\LegalPleadings\Support\PleadingBlocks;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The paragraph rule of the exported files, which has to agree with
 * `blocksOf()` in `pleading-document.tsx` — the recued block on screen must be
 * the recued block in the PDF and the DOCX.
 */
final class PleadingBlocksTest extends TestCase
{
    #[Test]
    public function a_blank_line_separates_paragraphs_and_inner_breaks_stay(): void
    {
        $blocks = PleadingBlocks::of("Primeiro.\n\n  \n\nPedidos:\na) um;\nb) outro.");

        $this->assertSame([
            ['lines' => ['Primeiro.'], 'citation' => false],
            ['lines' => ['Pedidos:', 'a) um;', 'b) outro.'], 'citation' => false],
        ], $blocks);
    }

    #[Test]
    public function a_marked_paragraph_is_a_citation_without_its_markers(): void
    {
        $this->assertSame(
            [['lines' => ['Linha um', 'linha dois'], 'citation' => true]],
            PleadingBlocks::of("> Linha um\n>linha dois"),
        );
    }

    #[Test]
    public function a_paragraph_marked_only_in_part_is_not_a_citation(): void
    {
        $this->assertFalse(PleadingBlocks::of("> Linha um\nlinha dois")[0]['citation']);
    }

    #[Test]
    public function a_long_quoted_paragraph_is_recognised_and_a_short_one_is_not(): void
    {
        $long = '“'.str_repeat('palavra ', 40).'”.';
        $short = '“Curta demais para recuar.”';

        $this->assertTrue(PleadingBlocks::of($long)[0]['citation']);
        $this->assertFalse(PleadingBlocks::of($short)[0]['citation']);
    }
}
