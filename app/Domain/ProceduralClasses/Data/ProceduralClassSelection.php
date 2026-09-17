<?php

declare(strict_types=1);

namespace App\Domain\ProceduralClasses\Data;

use App\Domain\ProceduralClasses\Models\ProceduralClass;

/**
 * Which class the agent chose, and why.
 *
 * Half of a payload rather than a payload: the wire shape has a single owner,
 * `LegalCaseClassification`, and that is why there is no `toArray()` here. Two
 * objects each publishing their own half is how the key names drift apart.
 *
 * The class travels as the model for the same reason the area does — the label
 * is shown, the uuid is persisted, and looking the row up twice is how the two
 * stop agreeing.
 */
final readonly class ProceduralClassSelection
{
    public function __construct(
        public ProceduralClass $proceduralClass,
        public string $justification,
    ) {}
}
