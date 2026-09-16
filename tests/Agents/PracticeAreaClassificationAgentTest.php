<?php

declare(strict_types=1);

namespace Tests\Agents;

use App\Domain\PracticeAreas\Actions\ClassifyPracticeArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The agent against the real database and a real Ollama, no fakes.
 *
 * This is the integration the rest of the system will do: hand the agent the
 * `facts` of a matter and take back an area plus the reasoning. The catalogue
 * arrives through RefreshDatabase for free, because the 24 areas are loaded by
 * migration rather than by a seeder.
 *
 * Grouped out of the default run: it needs Ollama up and spends seconds on
 * inference, which is not what `php artisan test` should cost. The group is
 * what enables it — naming the suite alone finds nothing, since phpunit.xml
 * excludes the group.
 *
 *   composer test:agents
 */
#[Group('agents')]
final class PracticeAreaClassificationAgentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_classifies_a_narrative_of_facts_into_a_practice_area(): void
    {
        $facts = <<<'TXT'
        No dia 12/09/2026, por volta das 21h, eu estava em casa assistindo TV, quando de repente ouvi
        um barulho de batida de carro muito forte, que parecia ser dentro da minha àrea residencial. Ao sair para verificar,
        notei que um homem havia batido no meu portão, causando a quebra do motor eletrico e o entortamento do portão de alumínio.
        Ao tentar conversar com o homem, ele se recusou a se identificar, agiu de forma agressiva e fugiu. Notei que ele estava com sinais de embriaguez.
        Por sorte, consegui capturar a placa do carro do homem, que correspondia na numeração YTD123, e era do modelo Chevrolet Onix.
        TXT;

        $classification = ClassifyPracticeArea::run($facts);

        // The point of this test is to look at the answer, and PHPUnit swallows
        // stdout — STDERR is what actually reaches the terminal.
        fwrite(STDERR, PHP_EOL.json_encode([
            'fatos' => $facts,
            'resposta' => $classification->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL.PHP_EOL);

        // Deliberately loose. Pinning the expected area would turn ordinary
        // model variation into a red build; what has to hold is that the answer
        // resolves to a real row and comes with a reason.
        $this->assertTrue($classification->practiceArea->exists);
        $this->assertNotSame('', $classification->justification);

        // The id is what a caller writes to `legal_cases.practice_area_id`, so
        // it has to be the real row's — not an empty key in the payload.
        $this->assertSame(
            $classification->practiceArea->id,
            $classification->toArray()['practice_area']['id'],
        );
    }
}
