<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Enums;

/**
 * The logos an account may carry: one per interface theme, and the pleading's.
 *
 * A mark drawn for a white page often disappears on a dark one — a black
 * wordmark, a navy seal —, so the dark theme gets a file of its own instead of
 * a CSS filter that would invert the brand's colours along with the ink.
 *
 * The pleading's is a third file and not a reuse of the light one, because the
 * two are drawn for different boxes: the sidebar fits its logo into a 32 px
 * square, where only the brand's symbol survives, and the letterhead has room
 * for the wide mark with the firm's name beside it.
 *
 * Each case knows every name it goes by — the form field, the column, the URL
 * — so the validation, the storage and the route walk the cases instead of
 * spelling each logo out twice.
 */
enum AccountLogo: string
{
    case Light = 'light';
    case Dark = 'dark';
    case Pleading = 'pleading';

    /**
     * How validation messages name the field.
     */
    public function attribute(): string
    {
        return match ($this) {
            self::Light => 'logo',
            self::Dark => 'logo para tema escuro',
            self::Pleading => 'logo da minuta',
        };
    }

    /**
     * The multipart field that carries a new file.
     */
    public function field(): string
    {
        return match ($this) {
            self::Light => 'logo',
            self::Dark => 'logo_dark',
            self::Pleading => 'logo_pleading',
        };
    }

    /**
     * The boolean field that asks for the stored file to go.
     */
    public function removalField(): string
    {
        return "remove_{$this->field()}";
    }

    /**
     * The `accounts` column holding the path on the disk.
     */
    public function column(): string
    {
        return "{$this->field()}_path";
    }

    /**
     * The `tema` of the logo URL — Portuguese, like every other URL. The
     * pleading's names the surface it is drawn on, the printed page, and rides
     * the same parameter rather than opening a second route to the same file
     * reader.
     */
    public function slug(): string
    {
        return match ($this) {
            self::Light => 'claro',
            self::Dark => 'escuro',
            self::Pleading => 'minuta',
        };
    }

    public static function fromSlug(string $slug): ?self
    {
        foreach (self::cases() as $logo) {
            if ($logo->slug() === $slug) {
                return $logo;
            }
        }

        return null;
    }
}
