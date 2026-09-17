<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Data;

use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Models\ProceduralClass;

/**
 * How a narrative of facts was framed: the area, the class, and the reason for
 * each.
 *
 * Lives in LegalCases, and not in either catalogue, because it is exactly the
 * two foreign keys of `legal_cases` plus the two sentences the lawyer reads
 * before accepting them. Neither catalogue should own a decision about the
 * other.
 *
 * Flat on purpose. The two steps return their own small objects so each stays
 * callable alone, but composing them here would make every consumer write
 * `$classification->proceduralClass->proceduralClass->code`. This is the wire
 * shape, and it is the only one — `PracticeAreaClassification` deliberately has
 * no `toArray()` of its own.
 *
 * The class is nullable because the selection step may find nothing to choose
 * from. No area in the catalogue is in that position today.
 */
final readonly class LegalCaseClassification
{
    public function __construct(
        public PracticeArea $practiceArea,
        public string $practiceAreaJustification,
        public ?ProceduralClass $proceduralClass,
        public ?string $proceduralClassJustification,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'practice_area' => [
                // Both ids are here to be written to `legal_cases` and nothing
                // else. They are generated when the catalogue migration loads,
                // so they differ between databases: never match on them, and
                // never write one into a fixture or a seed. The slug and the
                // CNJ code are the identifiers that survive a resync.
                'id' => $this->practiceArea->id,
                'slug' => $this->practiceArea->slug,
                'label' => $this->practiceArea->label,
            ],
            'practice_area_justification' => $this->practiceAreaJustification,
            'procedural_class' => $this->proceduralClass === null ? null : [
                'id' => $this->proceduralClass->id,
                'code' => $this->proceduralClass->code,
                'name' => $this->proceduralClass->name,
            ],
            'procedural_class_justification' => $this->proceduralClassJustification,
        ];
    }
}
