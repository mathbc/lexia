<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Enums;

use App\Domain\LegalCases\Actions\ResearchLegalCaseCourtDecisions;
use App\Domain\LegalCases\Actions\ResearchLegalCaseDoctrines;
use App\Domain\LegalCases\Actions\ResearchLegalCaseThemes;
use App\Domain\LegalCases\Actions\ResearchLegalCaseTheses;
use App\Domain\LegalCases\Actions\RunLegalCaseForensicReview;
use App\Domain\LegalCases\Actions\RunLegalCaseJurisprudence;

/**
 * What a research run looks for — the fifth step's three tabs and the sixth
 * step.
 *
 * The first three are ForensicReviewTab under another name, with the same
 * values, because they are the same thing seen from the queue: one tab, one
 * marker column, one row in `research_runs`. The fourth is the case law, which
 * has a step to itself and no tab. Keeping them in one enum is what lets one
 * table, one prop and one polling hook serve both steps.
 *
 * The values travel to the screen as the keys of `researchRuns`, so they are
 * English like every other enum value.
 */
enum ResearchSubject: string
{
    case Theses = 'theses';
    case Themes = 'themes';
    case Doctrines = 'doctrines';
    case CourtDecisions = 'court-decisions';

    public static function fromTab(ForensicReviewTab $tab): self
    {
        return self::from($tab->value);
    }

    /**
     * The fifth step's tab this subject is, or null for the case law.
     */
    public function tab(): ?ForensicReviewTab
    {
        return ForensicReviewTab::tryFrom($this->value);
    }

    /**
     * What the screen is told when the run did not come back.
     */
    public function failure(): string
    {
        return $this->tab()?->failure()
            ?? 'A pesquisa de jurisprudência não pôde ser concluída. Nada foi gravado nesta etapa.';
    }

    /**
     * The stage's name in the execution log — see `RecordAgentFailure`.
     */
    public function operation(): string
    {
        return $this->tab()?->operation() ?? 'jurisprudence.court_decisions';
    }

    /**
     * The Action to blame in the execution log when no agent can be read from
     * the failure — the one that held the subject.
     *
     * @return class-string
     */
    public function stage(): string
    {
        return match ($this) {
            self::Theses => ResearchLegalCaseTheses::class,
            self::Themes => ResearchLegalCaseThemes::class,
            self::Doctrines => ResearchLegalCaseDoctrines::class,
            self::CourtDecisions => ResearchLegalCaseCourtDecisions::class,
        };
    }

    /**
     * How long the job that researches this subject may run, which is also
     * how long a `running` row is believed before it counts as interrupted.
     */
    public function timeout(): int
    {
        return $this === self::CourtDecisions
            ? RunLegalCaseJurisprudence::TIMEOUT
            : RunLegalCaseForensicReview::TIMEOUT;
    }
}
