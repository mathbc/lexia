<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Data;

use App\Domain\LegalThemes\Data\LegalCaseThemeListData;
use Carbon\CarbonImmutable;

/**
 * What one theme selection run found: the links, what it searched, and how
 * wide it looked.
 *
 * The sibling of LegalResearchData for the sixth step's second tab, and much
 * smaller, because the run is: no portal is opened and no citation can fail a
 * guard — the answer is chosen from the retrieved candidates by `enum` — so
 * there is nothing to report beside the list but the questions of law the
 * catalogue was searched with and how many candidates the agent was shown.
 *
 * Ids and strings only, no model: this is the return value of a task in the
 * forensic review's `Concurrency::run`, and it crosses `serialize()`.
 */
final readonly class LegalThemeResearchData
{
    /**
     * @param  list<string>  $questions  LegalQuestionFormulationAgent's, most central first
     */
    public function __construct(
        public LegalCaseThemeListData $themes,
        public int $considered,
        public array $questions,
    ) {}

    /**
     * The shape `legal_cases.theme_findings` holds — the step's marker.
     *
     * `researched_at` is what makes it a marker: present means a run happened,
     * and the tab does not run it again on its own. `considered` is how many
     * themes the retrieval handed the agent, and `questions` what the catalogue
     * was searched with — the screen lists them under the themes, so that a
     * lawyer who misses a theme can see which angle of the case was never
     * asked, rather than wonder whether anything was asked at all.
     *
     * @return array{considered: int, questions: list<string>, researched_at: string}
     */
    public function findings(): array
    {
        return [
            'considered' => $this->considered,
            'questions' => $this->questions,
            'researched_at' => CarbonImmutable::now()->toIso8601String(),
        ];
    }
}
