<?php

declare(strict_types=1);

namespace App\Domain\Shared\Contracts;

/**
 * Implemented by backed enums whose values are shown to the user.
 *
 * Identifiers stay in English; only the label crosses into Portuguese, so the
 * UI never has to duplicate the translation of a domain concept.
 */
interface HasLabel
{
    public function label(): string;

    /**
     * Every case as `{value, label}`, ready to feed a <Select> on the frontend.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array;
}
