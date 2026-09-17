<?php

declare(strict_types=1);

namespace App\Domain\PracticeAreas\Actions;

use App\Ai\Agents\PracticeAreaClassificationAgent;
use App\Domain\PracticeAreas\Data\PracticeAreaClassification;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Rag\KnowledgeBase;
use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Suggest the practice area a narrative of facts belongs to.
 *
 * The first step of ClassifyLegalCase, and still callable on its own — the area
 * is useful before the class is, and the manual form may want only this.
 *
 * Deliberately without an `asController()`: nothing routes here yet. The shell
 * arrives when the pleading form asks for a suggestion, and until then this
 * stays a plain use case that a form, a job or a test can call the same way.
 *
 * The catalogue is read once and used twice — to build the agent's list of
 * permitted answers and to resolve the answer back into a row. Passing the
 * collection through instead of querying again is what makes the slug returned
 * by the model provably one of the rows the caller already holds.
 *
 * Areas are matched by slug, never by uuid: the ids are generated when the
 * catalogue migration loads and differ between databases.
 */
final class ClassifyPracticeArea
{
    use AsAction;

    public function __construct(private readonly KnowledgeBase $knowledge) {}

    public function handle(string $facts): PracticeAreaClassification
    {
        $facts = trim($facts);

        if ($facts === '') {
            throw new RuntimeException('Não há fatos para classificar.');
        }

        $areas = $this->areas();

        $response = (new PracticeAreaClassificationAgent(
            areas: $areas->map(static fn (PracticeArea $area): array => [
                'slug' => $area->slug,
                'label' => $area->label,
            ])->all(),
            knowledge: $this->knowledge->get('practice-areas'),
        ))->prompt($facts);

        return new PracticeAreaClassification(
            practiceArea: $this->resolve($areas, (string) $response['practice_area_slug']),
            justification: trim((string) $response['justification']),
        );
    }

    /**
     * @return Collection<int, PracticeArea>
     */
    private function areas(): Collection
    {
        $areas = PracticeArea::query()->orderBy('position')->get();

        if ($areas->isEmpty()) {
            throw new RuntimeException(
                'Catálogo de áreas de atuação vazio: rode as migrations antes de classificar.'
            );
        }

        return $areas;
    }

    /**
     * @param  Collection<int, PracticeArea>  $areas
     */
    private function resolve(Collection $areas, string $slug): PracticeArea
    {
        $area = $areas->firstWhere('slug', $slug);

        // The schema constrains the slug to this very list, so a miss is a
        // broken integration — a provider that ignored the format, or a
        // catalogue that changed under a running process — and not a case the
        // caller could sensibly recover from.
        if (! $area instanceof PracticeArea) {
            throw new RuntimeException("O agente devolveu uma área desconhecida: {$slug}");
        }

        return $area;
    }
}
