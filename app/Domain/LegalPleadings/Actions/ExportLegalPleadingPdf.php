<?php

declare(strict_types=1);

namespace App\Domain\LegalPleadings\Actions;

use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalPleadings\Models\LegalPleading;
use App\Domain\LegalPleadings\Support\PleadingFile;
use App\Domain\Users\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpFoundation\Response;

/**
 * The latest version of the draft as a PDF, letterhead included.
 *
 * The page is the one the screen draws: A4, ABNT margins of 3 x 2 cm, Times
 * 12pt at 1.5 line spacing and the long citation recued by 4 cm. The letterhead
 * repeats on every page inside the top margin, as printed letterhead paper does.
 *
 * Rendered by dompdf, which needs no binary on the server and no network: a
 * pleading's text never leaves the machine to become a file. `isRemoteEnabled`
 * stays off for the same reason — the template references nothing outside it.
 *
 * No agent here, and no write: exporting is reading.
 */
final class ExportLegalPleadingPdf
{
    use AsAction;

    public function handle(LegalCase $legalCase, LegalPleading $pleading, ?User $author = null): string
    {
        $file = PleadingFile::for($legalCase, $pleading, $author);

        $dompdf = new Dompdf((new Options)->setIsRemoteEnabled(false)->setDefaultFont('Times-Roman'));
        $dompdf->loadHtml(view('pleadings.pdf', ['file' => $file])->render(), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
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
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$file->basename}.pdf\"",
        ]);
    }
}
