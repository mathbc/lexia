<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Domain\LegalCases\Data\LegalCaseClassification;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The shape the classification travels in, without spending an inference on it.
 *
 * Built from real catalogue rows: the ids are the point of the payload, and a
 * fabricated model has none.
 */
final class LegalCaseClassificationPayloadTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_carries_both_entities_and_a_justification_each(): void
    {
        $area = PracticeArea::query()->where('slug', 'civil')->sole();
        $class = ProceduralClass::query()->where('code', 7)->sole();

        $payload = (new LegalCaseClassification(
            practiceArea: $area,
            practiceAreaJustification: 'O réu é um particular e não há relação de consumo.',
            proceduralClass: $class,
            proceduralClassJustification: 'O pedido é indenizatório e não há rito próprio.',
        ))->toArray();

        $this->assertSame(
            ['practice_area', 'practice_area_justification', 'procedural_class', 'procedural_class_justification'],
            array_keys($payload),
        );

        // The ids are here to be written to `legal_cases`, so they have to be
        // the rows' own — the slug and the CNJ code are what a human reads.
        $this->assertSame($area->id, $payload['practice_area']['id']);
        $this->assertSame('civil', $payload['practice_area']['slug']);
        $this->assertSame($class->id, $payload['procedural_class']['id']);
        $this->assertSame(7, $payload['procedural_class']['code']);
        $this->assertSame('Procedimento Comum Cível', $payload['procedural_class']['name']);
    }

    #[Test]
    public function a_missing_class_empties_its_half_without_changing_the_shape(): void
    {
        $area = PracticeArea::query()->where('slug', 'civil')->sole();

        $payload = (new LegalCaseClassification(
            practiceArea: $area,
            practiceAreaJustification: 'O réu é um particular.',
            proceduralClass: null,
            proceduralClassJustification: null,
        ))->toArray();

        $this->assertNull($payload['procedural_class']);
        $this->assertNull($payload['procedural_class_justification']);
        $this->assertSame('civil', $payload['practice_area']['slug']);
    }
}
