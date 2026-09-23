<?php

declare(strict_types=1);

namespace App\Domain\LegalPleadings\Support;

use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalPleadings\Models\LegalPleading;
use App\Domain\Users\Models\User;
use Illuminate\Support\Str;

/**
 * What an exported file of the draft is made of, whatever its format.
 *
 * The PDF and the DOCX print the same thing the Minuta tab draws — the
 * letterhead as frame and the **latest saved version** under it — so both
 * Actions start here. The latest *saved* version, and not the text in the
 * browser: an export is a document someone may file, and it has to be a version
 * the history can point to. The screen disables the buttons while there are
 * unsaved edits for the same reason.
 *
 * The letterhead is recomposed at export time from the account and the lawyer
 * who is exporting, exactly as the screen does. It was never part of `content`.
 */
final readonly class PleadingFile
{
    /**
     * @param  array{firm: string|null, signer: string|null, contact: string|null}  $letterhead
     * @param  list<array{lines: list<string>, citation: bool}>  $blocks
     */
    private function __construct(
        public array $letterhead,
        public array $blocks,
        public string $basename,
        public string $title,
    ) {}

    public static function for(LegalCase $legalCase, LegalPleading $pleading, ?User $author): self
    {
        $legalCase->loadMissing(['account', 'customer', 'proceduralClass']);

        $customer = $legalCase->customer->displayName();

        return new self(
            letterhead: PleadingLetterhead::lines($legalCase->account, $author),
            blocks: PleadingBlocks::of($pleading->content),
            basename: 'minuta-'.Str::slug($customer).'-v'.$pleading->version,
            title: "Minuta — {$customer} · {$legalCase->proceduralClass->name}",
        );
    }
}
