<?php

declare(strict_types=1);

namespace Tests\Feature\LegalPleadings;

use App\Domain\Accounts\Models\Account;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalPleadings\Models\LegalPleading;
use App\Domain\LegalPleadings\Support\PleadingFile;
use App\Domain\LegalPleadings\Support\PleadingLogo;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\Shared\Converter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * The Minuta tab's exports: the latest saved version, letterhead included, as a
 * PDF and as a DOCX.
 *
 * The DOCX is asserted by opening it — it is a zip of XML, so the body and the
 * header can be read back and it can be pinned that the letterhead sits in the
 * **header** (where Word repeats it and editing the body cannot reach it) and
 * that the long citation carries the 4 cm indent. The PDF's streams are
 * compressed, so for it the contract is the type, the name and a valid file —
 * plus the image object, whose dictionary is written in the clear, for the
 * account's pleading logo.
 */
final class ExportLegalPleadingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_pdf_is_the_latest_version_as_a_download(): void
    {
        [, $owner, $case] = $this->pleading();

        LegalPleading::factory()->forLegalCase($case)->version(1)->withContent('Antiga.')->create();
        LegalPleading::factory()->forLegalCase($case)->version(2)->withContent('Atual.')->create();

        $response = $this->actingAs($owner)
            ->get(route('legal-cases.pleading.pdf', $case))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringContainsString('-v2.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
        // Sem logo da minuta, o timbre é só texto.
        $this->assertStringNotContainsString('/Subtype /Image', (string) $response->getContent());
    }

    /**
     * The select on the Minuta tab opens older versions, and the export prints
     * the one on screen — a file of another version would be a document nobody
     * was reading.
     */
    #[Test]
    public function the_pdf_prints_the_version_the_url_names(): void
    {
        [, $owner, $case] = $this->pleading();

        LegalPleading::factory()->forLegalCase($case)->version(1)->withContent('Antiga.')->create();
        LegalPleading::factory()->forLegalCase($case)->version(2)->withContent('Atual.')->create();

        $response = $this->actingAs($owner)
            ->get(route('legal-cases.pleading.pdf', ['legalCase' => $case, 'versao' => 1]))
            ->assertOk();

        $this->assertStringContainsString('-v1.pdf', (string) $response->headers->get('Content-Disposition'));
    }

    #[Test]
    public function the_docx_prints_the_version_the_url_names(): void
    {
        [, $owner, $case] = $this->pleading();

        LegalPleading::factory()->forLegalCase($case)->version(1)->withContent('Texto antigo.')->create();
        LegalPleading::factory()->forLegalCase($case)->version(2)->withContent('Texto atual.')->create();

        $response = $this->actingAs($owner)
            ->get(route('legal-cases.pleading.docx', ['legalCase' => $case, 'versao' => 1]))
            ->assertOk();

        $this->assertStringContainsString('-v1.docx', (string) $response->headers->get('Content-Disposition'));

        [$body] = $this->docxParts((string) $response->getContent());

        $this->assertStringContainsString('Texto antigo.', $body);
        $this->assertStringNotContainsString('Texto atual.', $body);
    }

    #[Test]
    public function exporting_a_version_that_does_not_exist_is_not_found(): void
    {
        [, $owner, $case] = $this->pleading();
        LegalPleading::factory()->forLegalCase($case)->version(1)->create();

        $this->actingAs($owner)
            ->get(route('legal-cases.pleading.pdf', ['legalCase' => $case, 'versao' => 9]))
            ->assertNotFound();
    }

    #[Test]
    public function the_pdf_prints_the_pleading_logo_in_the_letterhead(): void
    {
        [$account, $owner, $case] = $this->pleading();
        $this->givePleadingLogo($account);

        LegalPleading::factory()->forLegalCase($case)->withContent('Atual.')->create();

        $pdf = (string) $this->actingAs($owner)
            ->get(route('legal-cases.pleading.pdf', $case))
            ->assertOk()
            ->getContent();

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('/Subtype /Image', $pdf);
    }

    #[Test]
    public function the_docx_prints_the_pleading_logo_in_the_header_and_not_in_the_body(): void
    {
        [$account, $owner, $case] = $this->pleading();
        $this->givePleadingLogo($account);

        LegalPleading::factory()->forLegalCase($case)->withContent('Atual.')->create();

        $docx = (string) $this->actingAs($owner)
            ->get(route('legal-cases.pleading.docx', $case))
            ->assertOk()
            ->getContent();

        [$body, $header, $media] = $this->docxParts($docx);

        $this->assertCount(1, $media);
        $this->assertStringContainsString('<v:imagedata', $header);
        $this->assertStringNotContainsString('<v:imagedata', $body);
        // O texto continua no timbre, abaixo da logo.
        $this->assertStringContainsString(htmlspecialchars($owner->name), $header);
    }

    /**
     * Centrada acima das três linhas, e não numa tabela ao lado delas. Empilhada,
     * a logo não cabe nos 2 cm que o timbre tem dentro da margem ABNT, então o
     * corpo desce exatamente a altura dela mais o respiro abaixo.
     */
    #[Test]
    public function the_docx_stacks_the_pleading_logo_above_the_text_and_lowers_the_body_by_it(): void
    {
        [$account, $owner, $case] = $this->pleading();
        $this->givePleadingLogo($account);

        LegalPleading::factory()->forLegalCase($case)->withContent('Atual.')->create();

        $docx = (string) $this->actingAs($owner)
            ->get(route('legal-cases.pleading.docx', $case))
            ->assertOk()
            ->getContent();

        [$body, $header] = $this->docxParts($docx);

        $this->assertStringNotContainsString('<w:tbl>', $header);
        $this->assertMatchesRegularExpression('/<w:jc w:val="center"\/>.*<v:imagedata/s', $header);
        $this->assertLessThan(
            strpos($header, htmlspecialchars($owner->name)),
            strpos($header, '<v:imagedata'),
        );

        $logo = PleadingLogo::for($account);
        $this->assertNotNull($logo);
        $expected = PleadingFile::TOP_MARGIN_CM + $logo->size()['height'] + PleadingLogo::GAP_CM;

        $this->assertSame((int) round(Converter::cmToTwip($expected)), $this->topMargin($body));
    }

    /**
     * A coluna que aponta para um arquivo que sumiu do disco não derruba a
     * exportação: o timbre é moldura, e a peça sem ela continua sendo a peça.
     */
    #[Test]
    public function a_pleading_logo_missing_from_the_disk_exports_without_it(): void
    {
        Storage::fake(Account::FILES_DISK);
        [$account, $owner, $case] = $this->pleading();
        $account->update(['logo_pleading_path' => "accounts/{$account->id}/logo-pleading-sumiu.png"]);

        LegalPleading::factory()->forLegalCase($case)->withContent('Atual.')->create();

        $pdf = (string) $this->actingAs($owner)
            ->get(route('legal-cases.pleading.pdf', $case))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('/Subtype /Image', $pdf);
    }

    #[Test]
    public function the_docx_carries_the_body_and_the_letterhead_in_the_header(): void
    {
        [$account, $owner, $case] = $this->pleading();

        $citation = '> '.str_repeat('Trecho citado do acórdão. ', 5);

        LegalPleading::factory()->forLegalCase($case)
            ->withContent("EXCELENTÍSSIMO SENHOR DOUTOR JUIZ\n\nDos fatos & do direito.\n\n{$citation}")
            ->create();

        $response = $this->actingAs($owner)
            ->get(route('legal-cases.pleading.docx', $case))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->assertStringContainsString('-v1.docx', (string) $response->headers->get('Content-Disposition'));

        [$body, $header, $media] = $this->docxParts((string) $response->getContent());

        $this->assertStringContainsString('EXCELENTÍSSIMO SENHOR DOUTOR JUIZ', $body);
        $this->assertStringContainsString('Dos fatos &amp; do direito.', $body);
        // O marcador de citação é gramática de edição: não vai para o arquivo.
        $this->assertStringNotContainsString('&gt; Trecho', $body);
        // 4 cm em twips.
        $this->assertStringContainsString('w:left="2268"', $body);

        $this->assertStringContainsString(htmlspecialchars(mb_strtoupper($account->displayName())), $header);
        $this->assertStringContainsString(htmlspecialchars($owner->name), $header);
        $this->assertStringNotContainsString(htmlspecialchars($owner->name), $body);
        $this->assertSame([], $media);
        // Sem logo, a margem superior é a da norma: 3 cm em twips.
        $this->assertSame(1701, $this->topMargin($body));
    }

    #[Test]
    public function a_pleading_with_no_draft_has_nothing_to_export(): void
    {
        [, $owner, $case] = $this->pleading();

        $this->actingAs($owner)->get(route('legal-cases.pleading.pdf', $case))->assertNotFound();
    }

    /**
     * Uma requisição por teste — ver SaveLegalPleadingTest: o `TenantContext`
     * sobrevive entre requisições do mesmo teste, e a segunda seria barrada
     * pelo escopo (404) em vez da Policy (403).
     */
    #[Test]
    public function exporting_the_pdf_of_another_account_is_refused(): void
    {
        [$theirs, $owner] = $this->foreignPleading();

        $this->actingAs($owner)->get(route('legal-cases.pleading.pdf', $theirs))->assertForbidden();
    }

    #[Test]
    public function exporting_the_docx_of_another_account_is_refused(): void
    {
        [$theirs, $owner] = $this->foreignPleading();

        $this->actingAs($owner)->get(route('legal-cases.pleading.docx', $theirs))->assertForbidden();
    }

    /**
     * The body, every header concatenated, and the names of the embedded images.
     *
     * @return array{0: string, 1: string, 2: list<string>}
     */
    private function docxParts(string $binary): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($path, $binary);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);

        $body = (string) $zip->getFromName('word/document.xml');
        $headers = '';
        $media = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (str_starts_with($name, 'word/header')) {
                $headers .= (string) $zip->getFromName($name);
            } elseif (str_starts_with($name, 'word/media/')) {
                $media[] = $name;
            }
        }

        $zip->close();
        unlink($path);

        return [$body, $headers, $media];
    }

    /** The top margin of the document's section, in twips. */
    private function topMargin(string $body): int
    {
        $this->assertMatchesRegularExpression('/<w:pgMar[^>]* w:top="(\d+)"/', $body);
        preg_match('/<w:pgMar[^>]* w:top="(\d+)"/', $body, $match);

        return (int) $match[1];
    }

    /**
     * Uma logo larga de verdade no disco falso, como o cadastro da conta a
     * gravaria.
     */
    private function givePleadingLogo(Account $account): void
    {
        Storage::fake(Account::FILES_DISK);

        $path = UploadedFile::fake()->image('timbre.png', 1600, 300)
            ->store($account->storageDirectory(), Account::FILES_DISK);

        $account->update(['logo_pleading_path' => $path]);
    }

    /**
     * @return array{0: LegalCase, 1: User}
     */
    private function foreignPleading(): array
    {
        [, $owner] = $this->accountWithOwner();
        [$otherAccount] = $this->accountWithOwner();

        $theirs = LegalCase::factory()->forAccount($otherAccount)->finalised()->create();
        LegalPleading::factory()->forLegalCase($theirs)->create();

        return [$theirs, $owner];
    }

    /**
     * @return array{0: Account, 1: User, 2: LegalCase}
     */
    private function pleading(): array
    {
        [$account, $owner] = $this->accountWithOwner();

        return [$account, $owner, LegalCase::factory()->forAccount($account)->finalised()->create()];
    }
}
