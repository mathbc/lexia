<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Enums;

use App\Domain\Shared\Concerns\ProvidesOptions;
use App\Domain\Shared\Contracts\HasLabel;

/**
 * The three tabs of the forensic review, which are researched in parallel but
 * marked, retried and failed apart.
 *
 * Each case names one tab of the fifth step and one task of the
 * `Concurrency::run` in ResearchLegalCaseForensicReview, and each has its own
 * marker column: `research_findings` for the theses, `theme_findings` for the
 * themes, `doctrine_findings` for the doctrine. The values travel in the
 * request (`tabs[]`) and in the error bag the screen reads, so they are English
 * like every other enum value; the Portuguese is the label.
 */
enum ForensicReviewTab: string implements HasLabel
{
    use ProvidesOptions;

    case Theses = 'theses';
    case Themes = 'themes';
    case Doctrines = 'doctrines';

    public function label(): string
    {
        return match ($this) {
            self::Theses => 'Teses',
            self::Themes => 'Temas',
            self::Doctrines => 'Doutrinas',
        };
    }

    /**
     * What the screen is told when this half did not come back.
     */
    public function failure(): string
    {
        return match ($this) {
            self::Theses => 'A pesquisa de teses não pôde ser concluída. Nada foi gravado nesta aba.',
            self::Themes => 'A seleção de temas não pôde ser concluída. Nada foi gravado nesta aba.',
            self::Doctrines => 'A pesquisa de doutrina não pôde ser concluída. Nada foi gravado nesta aba.',
        };
    }
}
