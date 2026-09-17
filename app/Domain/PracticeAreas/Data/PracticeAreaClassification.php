<?php

declare(strict_types=1);

namespace App\Domain\PracticeAreas\Data;

use App\Domain\PracticeAreas\Models\PracticeArea;

/**
 * What the classification agent decided about the area, and why.
 *
 * The area travels as the model, not as a slug: the next step needs the row to
 * find the classes linked to it, and every caller wants the label to show and
 * the id to persist onto `legal_cases`. Looking the row up twice is how the two
 * drift apart.
 *
 * Half of a payload rather than a payload — hence no `toArray()`. The wire
 * shape belongs to `LegalCaseClassification`, which owns both halves and can
 * keep their key names in step.
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
}
