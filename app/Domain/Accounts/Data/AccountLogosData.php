<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Data;

use App\Domain\Accounts\Enums\AccountLogo;
use Illuminate\Http\UploadedFile;

/**
 * What the form decided about each logo.
 *
 * A logo missing from `changes` is left alone; present with a file, it is
 * replaced; present with null, it is removed. "Untouched" and "removed" have
 * to be different things because the edit form posts every field on every
 * save, and a save that picked no file must not wipe the one already stored.
 */
final readonly class AccountLogosData
{
    /**
     * @param  list<array{AccountLogo, UploadedFile|null}>  $changes
     */
    public function __construct(public array $changes = []) {}

    /**
     * A new file wins over the removal flag: picking an image after clicking
     * "Remover" is changing one's mind, not asking for both.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        $changes = [];

        foreach (AccountLogo::cases() as $logo) {
            $file = $validated[$logo->field()] ?? null;

            if ($file instanceof UploadedFile) {
                $changes[] = [$logo, $file];
            } elseif ((bool) ($validated[$logo->removalField()] ?? false)) {
                $changes[] = [$logo, null];
            }
        }

        return new self($changes);
    }
}
