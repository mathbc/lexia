<?php

declare(strict_types=1);

namespace Tests\Feature\LegalPleadings;

use App\Domain\Accounts\Models\Account;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalPleadings\Models\LegalPleading;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
 * compressed, so for it the contract is the type, the name and a valid file.
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

        [$body, $header] = $this->docxParts((string) $response->getContent());

        $this->assertStringContainsString('EXCELENTÍSSIMO SENHOR DOUTOR JUIZ', $body);
        $this->assertStringContainsString('Dos fatos &amp; do direito.', $body);
        // O marcador de citação é gramática de edição: não vai para o arquivo.
        $this->assertStringNotContainsString('&gt; Trecho', $body);
        // 4 cm em twips.
        $this->assertStringContainsString('w:left="2268"', $body);

        $this->assertStringContainsString(htmlspecialchars(mb_strtoupper($account->displayName())), $header);
        $this->assertStringContainsString(htmlspecialchars($owner->name), $header);
        $this->assertStringNotContainsString(htmlspecialchars($owner->name), $body);
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
     * @return array{0: string, 1: string}
     */
    private function docxParts(string $binary): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($path, $binary);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);

        $body = (string) $zip->getFromName('word/document.xml');
        $headers = '';

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (str_starts_with($name, 'word/header')) {
                $headers .= (string) $zip->getFromName($name);
            }
        }

        $zip->close();
        unlink($path);

        return [$body, $headers];
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
