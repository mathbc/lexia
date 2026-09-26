<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Ai\Agents\LegalThemeSelectionAgent;
use App\Domain\LegalCases\Data\LegalThemeResearchData;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalCases\Support\LegalCaseDossier;
use App\Domain\LegalThemes\Data\LegalCaseThemeListData;
use App\Domain\LegalThemes\Models\GeneralRepercussion;
use App\Domain\LegalThemes\Models\LegalTheme;
use App\Domain\LegalThemes\Queries\LegalThemeCandidatesQuery;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Finds the STJ themes a pleading leans on — the RAG over `legal_themes`.
 *
 * Two halves, in series: `LegalThemeCandidatesQuery` retrieves the themes
 * closest to the narrative, and `LegalThemeSelectionAgent` reads them and keeps
 * the ones that apply. Retrieval without the reading would hand the lawyer
 * twelve themes about the same subject and call it a finding; the reading
 * without the retrieval would ask a model to recall the STJ's catalogue from
 * memory, which is the one thing this project never asks.
 *
 * Named after what the agent finds, like its siblings: the step's Action is
 * ResearchLegalCaseForensicReview, which calls this one beside the thesis
 * research, as two tasks of the same `Concurrency::run`. It writes nothing —
 * the caller does, in the transaction where it also writes the marker.
 *
 * **An empty catalogue throws rather than answering "no theme applies".** Zero
 * candidates would be an empty `enum`, which is invalid grammar, and even if
 * it were not, the answer would be a false statement about the pleading: the
 * themes were never looked at. The half fails, nothing is marked, and the
 * message says which command fills the table.
 */
final class ResearchLegalCaseThemes
{
    use AsAction;

    /**
     * How much of each long field reaches the prompt.
     *
     * A theme's question, thesis and scope average ~450 characters together,
     * but the longest runs past 5 KB — one outlier in the candidates would
     * spend the window of the other eleven. The cut is on each field and not
     * on the entry, so a long scope never swallows the question.
     */
    private const int FIELD_LIMIT = 1200;

    public function __construct(private readonly LegalThemeCandidatesQuery $candidates) {}

    public function handle(LegalCase $legalCase): LegalThemeResearchData
    {
        $facts = trim((string) $legalCase->facts);

        if ($facts === '') {
            throw new RuntimeException('Não há fatos para selecionar os temas.');
        }

        // As duas do enquadramento, e só elas: o dossiê desta pesquisa não tem
        // partes nem pedidos. loadMissing custa o mesmo para quem chegou por
        // route-model binding e para quem recarregou a peça num processo filho.
        $legalCase->loadMissing(['practiceArea', 'proceduralClass']);

        $candidates = $this->candidates->for(
            $facts,
            $legalCase->practiceArea->label,
            (int) config('ai.retrieval.theme_candidates'),
        );

        if ($candidates->isEmpty()) {
            throw new RuntimeException(
                'O catálogo de temas está vazio ou sem vetores — rode `php artisan lexia:import-legal-themes`.'
            );
        }

        return new LegalThemeResearchData(
            themes: LegalCaseThemeListData::fromAgent($this->select($legalCase, $candidates, $facts), $candidates),
            considered: $candidates->count(),
        );
    }

    /**
     * @param  Collection<int, LegalTheme>  $candidates
     * @return array<mixed> the agent's `themes` list
     */
    private function select(LegalCase $legalCase, Collection $candidates, string $facts): array
    {
        $response = (new LegalThemeSelectionAgent(
            framing: LegalCaseDossier::forThemes($legalCase),
            candidates: $candidates->map($this->candidate(...))->values()->all(),
        ))->prompt($facts);

        // Mesmo estreitamento das Actions irmãs: não dispara na prática, e
        // dispara quando a integração quebrou.
        if (! $response instanceof StructuredAgentResponse) {
            throw new RuntimeException('O agente de temas não devolveu uma resposta estruturada.');
        }

        $themes = $response->toArray()['themes'] ?? [];

        return is_array($themes) ? $themes : [];
    }

    /**
     * A theme as the prompt reads it.
     *
     * @return array{reference: string, heading: string, status: string, judging_body: string|null, question: string, settled_thesis: string|null, judgment_scope: string|null, repercussions: list<string>}
     */
    private function candidate(LegalTheme $theme): array
    {
        return [
            'reference' => $theme->reference(),
            'heading' => $theme->heading(),
            'status' => $theme->status,
            'judging_body' => $theme->judging_body?->label(),
            'question' => Str::limit($theme->question, self::FIELD_LIMIT),
            'settled_thesis' => $this->limited($theme->settled_thesis),
            'judgment_scope' => $this->limited($theme->judgment_scope),
            'repercussions' => $theme->generalRepercussions
                ->map(fn (GeneralRepercussion $repercussion): string => "Tema {$repercussion->number} — "
                    .Str::limit($repercussion->description, self::FIELD_LIMIT))
                ->values()
                ->all(),
        ];
    }

    private function limited(?string $text): ?string
    {
        return $text === null ? null : Str::limit($text, self::FIELD_LIMIT);
    }
}
