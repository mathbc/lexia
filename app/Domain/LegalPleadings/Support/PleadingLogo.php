<?php

declare(strict_types=1);

namespace App\Domain\LegalPleadings\Support;

use App\Domain\Accounts\Enums\AccountLogo;
use App\Domain\Accounts\Models\Account;
use Illuminate\Support\Facades\Storage;

/**
 * The account's pleading logo, read for the exporters.
 *
 * The screen gets a URL — ShowAccountLogo serves it through the Policy —, but
 * dompdf runs with remote access off and PhpWord embeds bytes, so the PDF and
 * the DOCX need the file itself, read here once per export from the private
 * disk. It is embedded as uploaded: the validation already caps it at 4000 px,
 * and a 4000 x 1000 PNG with transparency was measured costing dompdf under a
 * second.
 *
 * A column pointing at a file that is gone, or at bytes that are not an image,
 * yields no logo rather than a failed export: the letterhead is frame, and a
 * document without the frame is still the document.
 */
final readonly class PleadingLogo
{
    /**
     * The box the letterhead gives the logo, in centimetres, centred above the
     * three lines. Wider than tall because the mark printed here is the one
     * with the firm's name in it; the width keeps it a mark and not a banner
     * across the 16 cm text column. The height is what the logo takes from the
     * page, since stacked it lowers the body by its own height
     * (PleadingFile::topMargin()).
     */
    public const float MAX_WIDTH_CM = 6.0;

    public const float MAX_HEIGHT_CM = 1.5;

    /** The space between the logo and the firm's name, in centimetres. */
    public const float GAP_CM = 0.2;

    private function __construct(
        public string $contents,
        public string $mimeType,
        public int $width,
        public int $height,
    ) {}

    public static function for(?Account $account): ?self
    {
        $path = $account?->logoPath(AccountLogo::Pleading);
        $disk = Storage::disk(Account::FILES_DISK);

        if ($path === null || ! $disk->exists($path)) {
            return null;
        }

        $contents = (string) $disk->get($path);
        $size = @getimagesizefromstring($contents);

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return null;
        }

        return new self($contents, $size['mime'], $size[0], $size[1]);
    }

    /**
     * The image inline, for dompdf: with remote access off, a data URI is
     * the one source it reads without being given a filesystem chroot.
     */
    public function dataUri(): string
    {
        return "data:{$this->mimeType};base64,".base64_encode($this->contents);
    }

    /**
     * The size that fills the letterhead's box without distorting the image,
     * in centimetres. Both sides are given to the renderers, rather than one
     * and a max: dompdf does not honour `max-height` on an image.
     *
     * @return array{width: float, height: float}
     */
    public function size(): array
    {
        $scale = min(self::MAX_WIDTH_CM / $this->width, self::MAX_HEIGHT_CM / $this->height);

        return [
            'width' => round($this->width * $scale, 2),
            'height' => round($this->height * $scale, 2),
        ];
    }
}
