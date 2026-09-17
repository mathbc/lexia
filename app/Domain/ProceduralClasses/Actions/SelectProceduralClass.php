<?php

declare(strict_types=1);

namespace App\Domain\ProceduralClasses\Actions;

use App\Ai\Agents\ProceduralClassSelectionAgent;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Data\ProceduralClassSelection;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use App\Domain\ProceduralClasses\Queries\DescribedCandidateBudget;
use App\Domain\ProceduralClasses\Queries\ProceduralClassCandidatesQuery;
use App\Domain\ProceduralClasses\Queries\ProceduralClassRankingQuery;
use App\Rag\KnowledgeBase;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as Ranked;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * Choose the procedural class a matter should be filed under, within an area.
 *
 * The second step of the classification, and callable on its own: the manual
 * pleading form already knows the area the lawyer picked, and can ask for a
 * suggestion without paying for the first inference again.
 *
 * Like its sibling, the catalogue is read once and used twice — to build the
 * permitted answers and to resolve the answer back into a row. Classes are
 * matched by CNJ `code`, never by uuid or slug: the uuid differs between
 * databases, and the slug is not unique.
 *
 * Three steps between the catalogue and the prompt, and the middle one is new:
 *
 * 1. the area's filing classes come out of ProceduralClassCandidatesQuery;
 * 2. they are embedded if needed and sorted by proximity to the facts;
 * 3. DescribedCandidateBudget says how many of them fit described in full —
 *    description, matters and legal bases — and the rest arrive as a name and
 *    a code.
 *
 * Every one of them stays in the `enum`, so what the ranking spends is prompt
 * budget, never the set of answers the model may give. The descriptions now
 * carry deadlines, triggers and instruments, and the whole list of the worst
 * area would not fit the window beside the knowledge guide.
 *
 * Returns null instead of throwing when the area offers nothing to choose from.
 * That cannot happen with today's catalogue — every one of the 24 areas has
 * candidates — but an empty list would mean an empty `enum`, which is not a
 * question worth asking a model.
 */
final class SelectProceduralClass
{
    use AsAction;

    public function __construct(
        private readonly KnowledgeBase $knowledge,
        private readonly ProceduralClassCandidatesQuery $candidates,
        private readonly ProceduralClassRankingQuery $ranking,
    ) {}

    public function handle(PracticeArea $area, string $facts): ?ProceduralClassSelection
    {
        $facts = trim($facts);

        if ($facts === '') {
            throw new RuntimeException('Não há fatos para classificar.');
        }

        $candidates = $this->candidates->forArea($area);

        if ($candidates->isEmpty()) {
            return null;
        }

        $ranked = $this->rank($candidates, $facts);

        $response = (new ProceduralClassSelectionAgent(
            areaLabel: $area->label,
            candidates: $this->toCandidates($ranked),
            knowledge: $this->knowledge->get('procedural-classes'),
        ))->prompt($facts);

        return new ProceduralClassSelection(
            proceduralClass: $this->resolve($candidates, (int) $response['procedural_class_code']),
            justification: trim((string) $response['justification']),
        );
    }

    /**
     * @param  Collection<int, ProceduralClass>  $candidates
     * @return Ranked<int, ProceduralClass>
     */
    private function rank(Collection $candidates, string $facts): Ranked
    {
        try {
            // Self-healing on purpose: `migrate:fresh` leaves the catalogue
            // without vectors, and an agent test should not need a separate
            // manual step. Prepared environments run
            // `lexia:embed-procedural-classes` and reach this with nothing
            // stale to do, so it costs one query.
            EmbedProceduralClasses::run($candidates);
        } catch (Throwable) {
            // Sem Ollama de embeddings a classificação ainda funciona: cai na
            // ordem do pivot, específicas antes das genéricas.
        }

        return $this->ranking->byRelevanceTo($candidates, $facts);
    }

    /**
     * Describe candidates in ranked order until the prompt budget runs out.
     *
     * Spending the budget by size rather than by count is what keeps the
     * enrichment free where it is free: 17 of the 24 areas fit whole, so they
     * lose nothing and gain the descriptions the civil trunk never had. Only
     * the wide ones — Família at 54 candidates and 33 KB — get cut, and there
     * the vector decides what survives.
     *
     * @param  Ranked<int, ProceduralClass>  $ranked
     * @return list<array{code: int, name: string, scope: string, description: string|null, subjects: list<string>, legal_bases: list<string>}>
     */
    private function toCandidates(Ranked $ranked): array
    {
        $described = DescribedCandidateBudget::fromConfig()->describedCount($ranked);

        return $ranked
            ->values()
            ->map(fn (ProceduralClass $class, int $position): array => $this->toCandidate(
                $class,
                described: $position < $described,
            ))
            ->all();
    }

    /**
     * @return array{code: int, name: string, scope: string, description: string|null, subjects: list<string>, legal_bases: list<string>}
     */
    private function toCandidate(ProceduralClass $class, bool $described): array
    {
        return [
            'code' => $class->code,
            'name' => $class->name,
            // Set by PracticeArea::proceduralClasses(); a class reached any
            // other way has no pivot, and then it is not a borrowed trunk class.
            'scope' => (string) ($class->pivot?->getAttribute('scope') ?? 'specific'),
            'description' => $described ? $class->description : null,
            'subjects' => $described ? $class->typical_subjects : [],
            'legal_bases' => $described ? $class->citedLegalBases() : [],
        ];
    }

    /**
     * @param  Collection<int, ProceduralClass>  $candidates
     */
    private function resolve(Collection $candidates, int $code): ProceduralClass
    {
        $class = $candidates->firstWhere('code', $code);

        // The schema constrains the code to this very list, so a miss is a
        // broken integration — a provider that ignored the grammar, or a
        // catalogue that changed under a running process — and not a case the
        // caller could sensibly recover from.
        if (! $class instanceof ProceduralClass) {
            throw new RuntimeException("O agente devolveu uma classe processual desconhecida: {$code}");
        }

        return $class;
    }
}
