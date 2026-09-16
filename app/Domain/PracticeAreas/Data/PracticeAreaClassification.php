<?php

declare(strict_types=1);

namespace App\Domain\PracticeAreas\Data;

use App\Domain\PracticeAreas\Models\PracticeArea;

/**
 * What the classification agent decided, and why.
 *
 * The area travels as the model, not as a slug: every caller of this is going
 * to want the label to show and the id to persist onto `legal_cases`, and
 * looking the row up twice is how the two drift apart.
 *
 * Unlike the other Data objects in this project there is no `fromArray()` —
 * this one is built from an agent response rather than from validated form
 * input, and that assembly belongs to the Action that owns both halves.
 */
final readonly class PracticeAreaClassification
{
    public function __construct(
        public PracticeArea $practiceArea,
        public string $justification,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'practice_area' => [
                'slug' => $this->practiceArea->slug,
                'label' => $this->practiceArea->label,
            ],
            'justification' => $this->justification,
        ];
    }
}
