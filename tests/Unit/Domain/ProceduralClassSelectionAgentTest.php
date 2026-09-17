<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Ai\Agents\ProceduralClassSelectionAgent;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The invariant the whole design rests on, asserted where it costs nothing: the
 * schema may only offer the codes it was handed.
 *
 * The payload's shape is checked in Tests\Feature\LegalCases\LegalCaseClassificationPayloadTest,
 * which needs real catalogue rows rather than fabricated ones.
 */
final class ProceduralClassSelectionAgentTest extends TestCase
{
    #[Test]
    public function the_schema_offers_exactly_the_candidate_codes_as_integers(): void
    {
        $agent = new ProceduralClassSelectionAgent(
            areaLabel: 'Direito Civil',
            candidates: [
                ['code' => 7, 'name' => 'Procedimento Comum Cível', 'scope' => 'generic', 'description' => null, 'subjects' => []],
                ['code' => 40, 'name' => 'Monitória', 'scope' => 'specific', 'description' => 'Prova escrita sem força executiva.', 'subjects' => ['Cobrança']],
                ['code' => 12154, 'name' => 'Execução de Título Extrajudicial', 'scope' => 'specific', 'description' => null, 'subjects' => []],
            ],
            knowledge: '# Guia',
        );

        $schema = $agent->schema(new JsonSchemaTypeFactory)['procedural_class_code']->toArray();

        // Ollama turns this into a grammar, which is what makes an invented
        // class something the model is unable to emit. assertSame is strict, so
        // this also pins the values as integers rather than numeric strings:
        // `code` is an int everywhere else, and the Action casts back to one.
        $this->assertSame([7, 40, 12154], $schema['enum']);
    }
}
