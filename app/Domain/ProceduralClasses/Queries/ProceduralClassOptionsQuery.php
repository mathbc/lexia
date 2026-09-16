<?php

declare(strict_types=1);

namespace App\Domain\ProceduralClasses\Queries;

use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Enums\Jurisdiction;
use App\Domain\ProceduralClasses\Models\ProceduralClass;

/**
 * The classes of one practice area, projected for the class picker.
 *
 * Loaded per area rather than all at once: the catalogue holds 615 classes and
 * an area offers at most 141 of them, so the form starts empty and fetches the
 * list once an area is chosen.
 *
 * The competences arrive resolved into `{value, label, short_label, branch,
 * degree}` so the branch × degree map stays in PHP and the picker only draws
 * and filters what it is handed.
 */
final class ProceduralClassOptionsQuery
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forArea(?string $slug): array
    {
        if ($slug === null || $slug === '') {
            return [];
        }

        $area = PracticeArea::query()->where('slug', $slug)->first();

        if ($area === null) {
            return [];
        }

        // The relation already orders 'specific' before 'generic', then by
        // name — an area like Família offers 74 classes and the 33 that are
        // actually its own have to come first.
        return $area->proceduralClasses
            ->map($this->toOption(...))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function toOption(ProceduralClass $class): array
    {
        return [
            'id' => $class->id,
            'code' => $class->code,
            'name' => $class->name,
            'abbreviation' => $class->abbreviation,
            'legal_basis' => $class->legalBasis(),
            'is_filing_class' => $class->is_filing_class,
            'scope' => (string) $class->pivot?->getAttribute('scope'),
            'jurisdictions' => $this->tags($class),
        ];
    }

    /**
     * 33 classes carry an empty array because the CNJ did not inform the
     * competence; tryFrom also drops anything a future resync adds before this
     * enum knows about it, rather than failing the whole screen.
     *
     * @return list<array<string, string>>
     */
    private function tags(ProceduralClass $class): array
    {
        $tags = array_map(
            static fn (string $value): ?array => Jurisdiction::tryFrom($value)?->toTag(),
            $class->jurisdictions,
        );

        return array_values(array_filter($tags));
    }
}
