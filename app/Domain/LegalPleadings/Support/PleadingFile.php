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
 * The logo is the account's pleading logo, and the file carries its bytes
 * because neither renderer is allowed to fetch a URL.
 */
final readonly class PleadingFile
{
    /** The ABNT top margin, the one the text-only letterhead fits into. */
    public const float TOP_MARGIN_CM = 3.0;

    /**
     * @param  array{firm: string|null, signer: string|null, contact: string|null}  $letterhead
     * @param  list<array{lines: list<string>, citation: bool}>  $blocks
     */
    private function __construct(
        public array $letterhead,
        public ?PleadingLogo $logo,
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
            logo: PleadingLogo::for($legalCase->account),
            blocks: PleadingBlocks::of($pleading->content),
            basename: 'minuta-'.Str::slug($customer).'-v'.$pleading->version,
            title: "Minuta — {$customer} · {$legalCase->proceduralClass->name}",
        );
    }

    /**
     * The page's top margin, in centimetres. The text-only letterhead fits in
     * the 2 cm it has inside the ABNT 3 cm; the logo stacked above the three
     * lines does not, so the body starts lower by exactly the logo and the gap
     * under it. The lines, the rule and the rule's distance to the body stay
     * where the text-only letterhead puts them.
     */
    public function topMargin(): float
    {
        if ($this->logo === null) {
            return self::TOP_MARGIN_CM;
        }

        return round(self::TOP_MARGIN_CM + $this->logo->size()['height'] + PleadingLogo::GAP_CM, 2);
    }
}
