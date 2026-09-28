<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Enums;

/**
 * The two logos an account may carry, one per interface theme.
 *
 * A mark drawn for a white page often disappears on a dark one — a black
 * wordmark, a navy seal —, so the dark theme gets a file of its own instead of
 * a CSS filter that would invert the brand's colours along with the ink.
 *
 * Each case knows every name it goes by — the form field, the column, the URL
 * — so the validation, the storage and the route walk the cases instead of
 * spelling each logo out twice.
 */
enum AccountLogo: string
{
    case Light = 'light';
    case Dark = 'dark';

    /**
     * How validation messages name the field.
     */
    public function attribute(): string
    {
        return match ($this) {
            self::Light => 'logo',
            self::Dark => 'logo para tema escuro',
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
     * The `tema` of the logo URL — Portuguese, like every other URL.
     */
    public function slug(): string
    {
        return match ($this) {
            self::Light => 'claro',
            self::Dark => 'escuro',
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
