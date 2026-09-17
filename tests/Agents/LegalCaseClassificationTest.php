<?php

declare(strict_types=1);

namespace Tests\Agents;

use App\Domain\LegalCases\Actions\ClassifyLegalCase;
use App\Domain\ProceduralClasses\Queries\ProceduralClassCandidatesQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The two agents against the real database and a real Ollama, no fakes.
 *
 * This is the integration the rest of the system will do: hand the facts of a
 * matter and take back the area, the procedural class and the reasoning for
 * each. The catalogue arrives through RefreshDatabase for free, because the 24
 * areas and the 615 classes are loaded by migration rather than by a seeder.
 *
 * One test, two inferences. A separate area-only test would be a third call
 * that tells us nothing the first half of this one does not.
 *
 * Grouped out of the default run: it needs Ollama up and spends seconds on
 * inference, which is not what `php artisan test` should cost. The group is
 * what enables it — naming the suite alone finds nothing, since phpunit.xml
 * excludes the group.
 *
 *   composer test:agents
 */
#[Group('agents')]
final class LegalCaseClassificationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_classifies_a_narrative_of_facts_into_an_area_and_a_procedural_class(): void
    {
        // $facts = <<<'TXT'
        // No dia 12/09/2026, por volta das 21h, eu estava em casa assistindo TV, quando de repente ouvi
        // um barulho de batida de carro muito forte, que parecia ser dentro da minha àrea residencial. Ao sair para verificar,
        // notei que um homem havia batido no meu portão, causando a quebra do motor eletrico e o entortamento do portão de alumínio.
        // Ao tentar conversar com o homem, ele se recusou a se identificar, agiu de forma agressiva e fugiu. Notei que ele estava com sinais de embriaguez.
        // Por sorte, consegui capturar a placa do carro do homem, que correspondia na numeração YTD123, e era do modelo Chevrolet Onix.
        // TXT;

        // $facts = <<<'TXT'
        // Em frente ao condomínio onde eu moro, existe uma obra da construtora Marinho, eles iniciam as obras todos os dias às 6 da manhã e terminam às 18h.
        // Porém a obra continua aos finais de semana e não para nem no domingo, causando barulho e poluição sonora.
        // O condomínio não faz nada para resolver o problema, e nem mesmo reconhece que é um problema. Além de dificultarem o trânsito por conta do tráfego de caminhões, ergueram
        // alguns carros de moradores e visitantes, sem autorização.
        // TXT;

        $facts = <<<'TXT'
        Uma moça entrou dentro do meu estabelecimento, fez com que eu buscasse algumas peças de roupa para ela provar, e enquanto eu estava buscando,
        Ela aproveitou para colocar outras peças de roupa dentro da bolsa, não escolheu nenhuma das peças que eu busquei e saiu sem pagar
        TXT;

        $classification = ClassifyLegalCase::run($facts);

        // The point of this test is to look at the answer, and PHPUnit swallows
        // stdout — STDERR is what actually reaches the terminal.
        fwrite(STDERR, PHP_EOL.json_encode([
            'fatos' => $facts,
            'resposta' => $classification->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL.PHP_EOL);

        // Deliberately loose on *which* area and *which* class. Pinning either
        // would turn ordinary model variation into a red build; what has to
        // hold is that both resolve to real rows and come with a reason.
        $this->assertTrue($classification->practiceArea->exists);
        $this->assertNotSame('', $classification->practiceAreaJustification);

        $this->assertNotNull($classification->proceduralClass);
        $this->assertNotSame('', (string) $classification->proceduralClassJustification);

        // This one is not loose, and it is the assertion that matters: the
        // class has to belong to the area that was chosen. The enum makes that
        // structurally true, so a failure here means the grammar was not
        // applied — a silent truncation, or a provider ignoring `format`.
        $candidates = (new ProceduralClassCandidatesQuery)->forArea($classification->practiceArea);

        $this->assertContains(
            $classification->proceduralClass->code,
            $candidates->pluck('code')->all(),
        );

        // The ids are what a caller writes to `legal_cases`, so they have to be
        // the real rows' — not empty keys in the payload.
        $payload = $classification->toArray();

        $this->assertSame($classification->practiceArea->id, $payload['practice_area']['id']);
        $this->assertSame($classification->proceduralClass->id, $payload['procedural_class']['id']);
        $this->assertSame($classification->proceduralClass->code, $payload['procedural_class']['code']);
    }
}
