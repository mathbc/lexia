<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Data;

use App\Domain\LegalThemes\Data\LegalCaseThemeListData;
use Carbon\CarbonImmutable;

/**
 * What one theme selection run found: the links, and how wide it looked.
 *
 * The sibling of LegalResearchData for the sixth step's second tab, and much
 * smaller, because the run is: no portal is opened and no citation can fail a
 * guard — the answer is chosen from the retrieved candidates by `enum` — so
 * there is nothing to report beside the list but how many candidates the
 * agent was shown.
 *
 * Ids and strings only, no model: this is the return value of a task in the
 * forensic review's `Concurrency::run`, and it crosses `serialize()`.
 */
final readonly class LegalThemeResearchData
{
    public function __construct(
        public LegalCaseThemeListData $themes,
        public int $considered,
    ) {}

    /**
     * The shape `legal_cases.theme_findings` holds — the step's marker.
     *
     * `researched_at` is what makes it a marker: present means a run happened,
     * including the one that found no theme that applies and wrote zero links.
     * `considered` is how many themes the retrieval handed the agent, which is
     * what lets the screen say "12 temas do catálogo consultados" beside an
     * empty tab, so that "nothing applies" does not read as "nothing looked".
     *
     * @return array{considered: int, researched_at: string}
     */
    public function findings(): array
    {
        return [
            'considered' => $this->considered,
            'researched_at' => CarbonImmutable::now()->toIso8601String(),
        ];
    }
}
