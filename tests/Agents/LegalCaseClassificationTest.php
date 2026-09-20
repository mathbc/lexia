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
 * The whole chain against the real database and the real providers, no fakes.
 *
 * This is the integration the rest of the system does: hand the facts of a
 * matter and take back the area, the procedural class, the reasoning for each,
 * the other party, what is being asked for and the theses the pleading can
 * argue. The catalogue arrives through RefreshDatabase for free, because the 24
 * areas and the 615 classes are loaded by migration rather than by a seeder.
 *
 * One test, **six inferences** — the five steps, with the thesis research
 * counting for two. A separate area-only test would be a call that tells us
 * nothing the first sixth of this one does not, and every step has its own test
 * next door about the quality of what it reads; what this one adds is that the
 * chain still reaches them all.
 *
 * ## What it costs, which changed when the research was wired in
 *
 * It used to be Ollama and seconds. It is now Ollama **and Gemini quota and the
 * open internet**, and it takes minutes: the last step searches the official
 * portals for real, and it is the slowest inference in the project because the
 * provider opens the pages before it answers.
 *
 * So read a red build here the way `LegalThesisResearchTest` asks to be read:
 * it can mean the STJ is slow or the quota is spent, and not that a prompt
 * regressed. Nothing about the research is asserted below, deliberately — which
 * theses a case supports is judgement, it is pinned loosely next door, and the
 * `stage()` of ClassifyLegalCase already guarantees that a failed search costs
 * the framing nothing. What is worth reading is the dump.
 *
 * Grouped out of the default run, which matters more than it used to: `php
 * artisan test` must never reach a paid provider. The group is what enables it
 * — naming the suite alone finds nothing, since phpunit.xml excludes the group.
 *
 *   composer test:agents
 *
 * ## The one place the real concurrency driver runs
 *
 * `setUp()` puts the driver back to `process`, against the `sync` that
 * phpunit.xml pins. Everywhere else `sync` is the only safe answer — a double
 * registered in this process's container does not cross into a child, so the
 * feature suite would silently reach a paid provider — but here there are no
 * doubles to lose, and this is consequently the only test that exercises
 * `ClassifyLegalCase`'s block as production runs it: three `artisan`
 * subprocesses, the return values crossing back through `serialize()`.
 *
 * It costs nothing extra. The same six inferences happen either way; they
 * merely overlap. What it buys is that a regression in what a task may capture,
 * or in what may cross the process boundary, fails here instead of in a
 * request.
 *
 * Two consequences of a child process worth knowing when reading a red build.
 * A child sees only **committed** data — fine here, because the catalogue comes
 * from migrations, which RefreshDatabase commits before it opens the
 * transaction, and because this test calls the Action directly and needs no
 * account rows. And the vectors that `EmbedProceduralClasses` self-heals inside
 * the child are written **outside** the test transaction, so they stay in
 * `lexia_testing`: harmless, idempotent, and the same thing a prepared
 * environment does on purpose.
 */
#[Group('agents')]
final class LegalCaseClassificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['concurrency.default' => 'process']);
    }

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

        $facts = <<<'TXT'
        Em frente ao condomínio onde eu moro, existe uma obra da construtora Marinho, eles iniciam as obras todos os dias às 6 da manhã e terminam às 18h.
        Porém a obra continua aos finais de semana e não para nem no domingo, causando barulho e poluição sonora.
        O condomínio não faz nada para resolver o problema, e nem mesmo reconhece que é um problema. Além de dificultarem o trânsito por conta do tráfego de caminhões, ergueram
        alguns carros de moradores e visitantes, sem autorização.
        TXT;

        // $facts = <<<'TXT'
        // Doutor, acabei de passar por um susto terrível aqui na loja de roupas porque flagrei uma pessoa furtando algumas peças de vestuário no meio do expediente,
        // escondendo as roupas dentro da bolsa enquanto disfarçava nas araras, e preciso saber exatamente como agir e quais providências tomar agora com as filmagens das câmeras de segurança para não ter nenhum problema jurídico.
        // TXT;

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

        // Loose on purpose, and it has to be: the narratives above may or may
        // not identify a defendant, and twelve nulls is a legitimate answer.
        // What is asserted is that the third agent answered at all — a null
        // here is the extraction having failed, which the Action swallows so
        // the framing survives, and which nothing else would report.
        $this->assertNotNull($classification->defendant);
        $this->assertArrayHasKey('defendant_name', $payload['defendant']);

        // Pelo mesmo motivo, e com a mesma folga: os relatos acima podem pedir
        // muito ou não pedir nada, e a lista vazia é resposta legítima. O nulo
        // não é — ele é a quarta inferência tendo caído, que a Action engole
        // para que o enquadramento sobreviva, e que nada mais reportaria.
        $this->assertNotNull($classification->requirements);
        $this->assertIsArray($payload['requirements']);
    }
}
