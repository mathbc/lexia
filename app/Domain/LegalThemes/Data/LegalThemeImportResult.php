<?php

declare(strict_types=1);

namespace App\Domain\LegalThemes\Data;

/**
 * What an import wrote: the precedents in the file, how many of them the
 * table did not have yet, and the STF repercussion links rebuilt for them.
 */
final readonly class LegalThemeImportResult
{
    public function __construct(
        public int $themes,
        public int $created,
        public int $repercussions,
    ) {}
}
