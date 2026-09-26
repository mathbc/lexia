<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Ai\Agents\LegalQuestionFormulationAgent;
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
 * Three steps, in series. `LegalQuestionFormulationAgent` rewrites the
 * narrative as the questions of law it raises, in the register the STJ writes
 * its themes in; `LegalThemeCandidatesQuery` retrieves the themes closest to
 * each question; and `LegalThemeSelectionAgent` reads them and ranks the ones
 * that bear on the case. The first step is what the others stand on: the
 * narrative itself is a poor query — every pleading researched with it linked
 * zero themes, because facts do not land near abstract questions of law — and
 * the questions put the right theme first (see the query's docblock).
 *
 * Retrieval without the reading would hand the lawyer twenty themes about the
 * same subjects and call it a finding; the reading without the retrieval would
 * ask a model to recall the STJ's catalogue from memory, which is the one
 * thing this project never asks — which is also why the formulation is told
 * never to name a theme.
 *
 * **It always comes back with themes.** The selection is a ranking with a
 * floor, and not a filter that may answer "none": the lawyer unticks what does
 * not serve, and a list to curate is worth more to them than an empty tab
 * whose emptiness they cannot check. The floor is in the schema and again in
 * LegalCaseThemeListData::fromAgent(), which tops the list up in retrieval
 * order if a provider ignores it.
 *
 * Named after what the agents find, like its siblings: the step's Action is
 * ResearchLegalCaseForensicReview, which calls this one beside the thesis
 * research, as two tasks of the same `Concurrency::run`. It writes nothing —
 * the caller does, in the transaction where it also writes the marker.
 *
 * **An empty catalogue throws rather than answering with nothing.** Zero
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
     * spend the window of the other nineteen. The cut is on each field and not
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

        $framing = LegalCaseDossier::forThemes($legalCase);
        $questions = $this->formulate($framing, $facts);

        $candidates = $this->candidates->for($questions, (int) config('ai.retrieval.theme_candidates'));

        if ($candidates->isEmpty()) {
            throw new RuntimeException(
                'O catálogo de temas está vazio ou sem vetores — rode `php artisan lexia:import-legal-themes`.'
            );
        }

        return new LegalThemeResearchData(
            themes: LegalCaseThemeListData::fromAgent($this->select($framing, $questions, $candidates, $facts), $candidates),
            considered: $candidates->count(),
            questions: $questions,
        );
    }

    /**
     * The questions of law the narrative raises, blanks and repeats dropped.
     *
     * @return list<string>
     */
    private function formulate(string $framing, string $facts): array
    {
        $questions = $this->structured(
            (new LegalQuestionFormulationAgent($framing))->prompt($facts),
            'questions',
        );

        $questions = array_values(array_unique(array_filter(
            array_map(static fn (mixed $question): string => is_string($question) ? trim($question) : '', $questions),
            static fn (string $question): bool => $question !== '',
        )));

        // Sem questão não há consulta, e consultar pelo relato cru é o que
        // devolvia zero temas: a metade falha em vez de fingir que buscou.
        if ($questions === []) {
            throw new RuntimeException('O agente não formulou nenhuma questão de direito para a busca de temas.');
        }

        return array_slice($questions, 0, LegalQuestionFormulationAgent::MAX_QUESTIONS);
    }

    /**
     * @param  list<string>  $questions
     * @param  Collection<int, LegalTheme>  $candidates
     * @return array<mixed> the agent's `themes` list
     */
    private function select(string $framing, array $questions, Collection $candidates, string $facts): array
    {
        return $this->structured(
            (new LegalThemeSelectionAgent(
                framing: $framing,
                questions: $questions,
                candidates: $candidates->map($this->candidate(...))->values()->all(),
            ))->prompt($facts),
            'themes',
        );
    }

    /**
     * One list out of a structured answer.
     *
     * @return array<mixed>
     */
    private function structured(mixed $response, string $key): array
    {
        // Mesmo estreitamento das Actions irmãs: não dispara na prática, e
        // dispara quando a integração quebrou.
        if (! $response instanceof StructuredAgentResponse) {
            throw new RuntimeException('O agente de temas não devolveu uma resposta estruturada.');
        }

        $list = $response->toArray()[$key] ?? [];

        return is_array($list) ? $list : [];
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
