<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\LegalCases\Data\LegalCaseClassification;
use App\Domain\PracticeAreas\Actions\ClassifyPracticeArea;
use App\Domain\ProceduralClasses\Actions\SelectProceduralClass;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Read the facts of a matter and frame it: practice area and procedural class.
 *
 * Two inferences in sequence, and the order is forced rather than chosen. The
 * classes a case may be filed under are the ones linked to its area, so the
 * list the second agent chooses from cannot exist until the first has answered.
 * Each step constrains its own answer with an `enum`, which Ollama turns into a
 * grammar — the guarantee that neither an invented area nor an invented class
 * is something the model is able to emit.
 *
 * The price is latency: two calls at Timeout(180) are not a request-cycle
 * budget, so the screen that asks for this will have to dispatch it rather than
 * await it. Both steps share one model on purpose — the second call then finds
 * it already loaded.
 *
 * Deliberately without an `asController()`: nothing routes here yet, same as
 * the step it wraps.
 */
final class ClassifyLegalCase
{
    use AsAction;

    public function handle(string $facts): LegalCaseClassification
    {
        $facts = trim($facts);

        if ($facts === '') {
            throw new RuntimeException('Não há fatos para classificar.');
        }

        $area = ClassifyPracticeArea::run($facts);

        // A wrong area makes the class wrong by construction, and this step has
        // no way to object. Both justifications travel separately so the lawyer
        // can see which of the two decisions to distrust.
        $class = SelectProceduralClass::run($area->practiceArea, $facts);

        return new LegalCaseClassification(
            practiceArea: $area->practiceArea,
            practiceAreaJustification: $area->justification,
            proceduralClass: $class?->proceduralClass,
            proceduralClassJustification: $class?->justification,
        );
    }
}
