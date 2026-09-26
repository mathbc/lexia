<?php

declare(strict_types=1);

namespace App\Domain\LegalThemes\Data;

/**
 * One theme a pleading leans on: which one, and why.
 *
 * Carries the theme's id and not the model, because this is what crosses the
 * process boundary of the forensic review's `Concurrency::run` — a LegalTheme
 * would drag its 768-float vector through `serialize()` for a screen that
 * reads two strings. The id is the persisted uuid, resolved from the prompt's
 * `reference` by LegalCaseThemeListData::fromAgent(), or posted back by the
 * browser, which only ever received it from us.
 */
final readonly class LegalCaseThemeData
{
    public function __construct(
        public string $legalThemeId,
        public ?string $reason,
    ) {}

    /**
     * Blank is null, as everywhere in the forms: a reason nobody wrote is not
     * a reason that says nothing.
     */
    public static function of(string $legalThemeId, mixed $reason): self
    {
        $reason = is_string($reason) ? trim($reason) : '';

        return new self($legalThemeId, $reason === '' ? null : $reason);
    }
}
