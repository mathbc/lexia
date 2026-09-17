<?php

declare(strict_types=1);

namespace App\Domain\ProceduralClasses\Actions;

use App\Ai\Agents\ProceduralClassSelectionAgent;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Data\ProceduralClassSelection;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use App\Domain\ProceduralClasses\Queries\ProceduralClassCandidatesQuery;
use App\Rag\KnowledgeBase;
use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

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

        $response = (new ProceduralClassSelectionAgent(
            areaLabel: $area->label,
            candidates: $candidates->map($this->toCandidate(...))->values()->all(),
            knowledge: $this->knowledge->get('procedural-classes'),
        ))->prompt($facts);

        return new ProceduralClassSelection(
            proceduralClass: $this->resolve($candidates, (int) $response['procedural_class_code']),
            justification: trim((string) $response['justification']),
        );
    }

    /**
     * @return array{code: int, name: string, scope: string, description: string|null, subjects: list<string>}
     */
    private function toCandidate(ProceduralClass $class): array
    {
        return [
            'code' => $class->code,
            'name' => $class->name,
            // Set by PracticeArea::proceduralClasses(); a class reached any
            // other way has no pivot, and then it is not a borrowed trunk class.
            'scope' => (string) ($class->pivot?->getAttribute('scope') ?? 'specific'),
            'description' => $class->description,
            'subjects' => $class->typical_subjects,
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
