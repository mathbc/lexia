<?php

declare(strict_types=1);

namespace App\Domain\LegalPleadings\Actions;

use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalPleadings\Models\LegalPleading;
use App\Domain\LegalPleadings\Support\PleadingFile;
use App\Domain\Users\Models\User;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;
use PhpOffice\PhpWord\Element\Header;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;
use Symfony\Component\HttpFoundation\Response;

/**
 * The latest version of the draft as a Word document, letterhead included.
 *
 * The one export meant to be **edited further**: a lawyer who files through a
 * firm template or finishes the document in Word gets real paragraphs and not a
 * picture of them. So the ABNT page is built from Word's own properties — A4,
 * 3 x 2 cm margins, Times New Roman 12pt at 1.5, a line of space between
 * paragraphs and the long citation as a 4 cm left indent — and the letterhead
 * goes into the section header, where Word repeats it on every page and where
 * editing the body cannot reach it.
 *
 * Two settings are not optional, and both produce a file Word calls corrupt when
 * missing: output escaping (PhpWord writes `&` raw by default, and "Fatos &
 * Direito" breaks the XML) and **integer** twips — `Converter` returns floats,
 * the schema accepts only whole numbers, so every measure goes through `twips()`
 * and the A4 size is written out rather than taken from the `A4` preset.
 *
 * No agent here, and no write: exporting is reading.
 */
final class ExportLegalPleadingDocx
{
    use AsAction;

    public function handle(LegalCase $legalCase, LegalPleading $pleading, ?User $author = null): string
    {
        $file = PleadingFile::for($legalCase, $pleading, $author);

        Settings::setOutputEscapingEnabled(true);

        $word = new PhpWord;
        $word->setDefaultFontName('Times New Roman');
        $word->setDefaultFontSize(12);
        $word->getDocInfo()->setTitle($file->title);

        $section = $word->addSection([
            'pageSizeW' => self::twips(21),
            'pageSizeH' => self::twips(29.7),
            'marginTop' => self::twips(3),
            'marginLeft' => self::twips(3),
            'marginRight' => self::twips(2),
            'marginBottom' => self::twips(2),
            'headerHeight' => self::twips(1),
        ]);

        $this->letterhead($section->addHeader(), $file);

        foreach ($file->blocks as $block) {
            $run = $section->addTextRun([
                'lineHeight' => 1.5,
                // Uma linha em branco entre parágrafos: 12pt a 1,5 são 18pt.
                'spaceAfter' => (int) Converter::pointToTwip(18),
                'indentation' => ['left' => $block['citation'] ? self::twips(4) : 0],
            ]);

            foreach ($block['lines'] as $index => $line) {
                if ($index > 0) {
                    $run->addTextBreak();
                }

                $run->addText($line);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'minuta');

        try {
            IOFactory::createWriter($word, 'Word2007')->save((string) $path);

            return (string) file_get_contents((string) $path);
        } finally {
            @unlink((string) $path);
        }
    }

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('view', $request->route('legalCase'));
    }

    public function asController(LegalCase $legalCase, ActionRequest $request): Response
    {
        $pleading = $legalCase->pleadings()->firstOrFail();
        $file = PleadingFile::for($legalCase, $pleading, $request->user());

        return response($this->handle($legalCase, $pleading, $request->user()), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => "attachment; filename=\"{$file->basename}.docx\"",
        ]);
    }

    /** Centímetros em twips inteiros — o único formato que o schema aceita. */
    private static function twips(float $centimeters): int
    {
        return (int) round(Converter::cmToTwip($centimeters));
    }

    private function letterhead(Header $header, PleadingFile $file): void
    {
        $centered = ['alignment' => Jc::CENTER, 'spaceAfter' => 0];

        if ($file->letterhead['firm'] !== null) {
            $header->addText($file->letterhead['firm'], ['bold' => true, 'size' => 13], $centered);
        }

        if ($file->letterhead['signer'] !== null) {
            $header->addText($file->letterhead['signer'], ['size' => 10], $centered);
        }

        if ($file->letterhead['contact'] !== null) {
            $header->addText($file->letterhead['contact'], ['size' => 8, 'color' => '666666'], $centered);
        }

        // O fio que separa o timbre do corpo, como a borda do cabeçalho na tela.
        $header->addText('', ['size' => 4], [
            'spaceAfter' => 0,
            'borderBottomSize' => 6,
            'borderBottomColor' => 'BBBBBB',
        ]);
    }
}
