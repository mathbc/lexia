<?php

declare(strict_types=1);

namespace Tests\Agents;

use App\Domain\LegalCases\Actions\ResearchLegalCaseTheses;
use App\Domain\LegalCases\Data\LegalResearchData;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use App\Domain\Requirements\Models\Requirement;
use App\Domain\Shared\Support\OfficialLegalSources;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The thesis research pair against the real provider, no fakes.
 *
 * Different from its four siblings in two ways that change how it should be
 * read.
 *
 * It is the only agent test that leaves the machine: it spends a Gemini quota
 * and depends on the internet and on the official portals being up, so a red
 * build here is not automatically a prompt regression — it can be the STJ being
 * slow. The assertions are written with that in mind, and the dump matters more
 * here than anywhere else.
 *
 * And it is the slowest by a wide margin, because the provider runs the
 * searches before it answers. Budget minutes, not seconds, and expect the two
 * cases together to outlast a default test timeout.
 *
 * **Everything this file asserts that does not depend on the model is also
 * asserted, deterministically and without a network, in
 * `tests/Unit/Domain/LegalResearchDataTest`.** That is the file to trust when
 * this one is red for reasons outside the code: the guard, the ceilings, the
 * correlation ids and the flattening are all pinned there.
 *
 * No RefreshDatabase, like the three extraction tests: the pleading is built in
 * memory with its relations set by hand, so nothing is written and no catalogue
 * is needed. `LegalCaseDossier::forResearch()` only ever touches the model it
 * is handed.
 *
 * ## What is pinned, and what deliberately is not
 *
 * The project's rule is firm on copying and loose on judgement, and here the
 * split falls in an unusual place, because **which** theses a case supports is
 * judgement all the way down. Pinning "it must find Súmula 430" would be
 * pinning a legal opinion to a build.
 *
 * So what is held firmly is the *contract* and the *guard*:
 *
 * - the shape of the payload, so a rename breaks here;
 * - that every surviving citation has an official source — that one is not a
 *   model behaviour at all, it is LegalResearchData, and it must hold for any
 *   model on any day;
 * - the ceilings, which are enforced in PHP;
 * - that a run which confirms nothing still answers, rather than raising.
 *
 * What is held loosely: how many theses, which ones, and how they are worded.
 *
 * ## The one thing worth watching in the dump
 *
 * Whether the research actually happened. A run that returns tidy theses with
 * `sources` empty and every `source_url` pointing at a court's front page is a
 * model answering from memory, which is the failure the two-agent split exists
 * to prevent and which no assertion can catch reliably — a fabricated súmula is
 * shaped exactly like a real one. Read the sheet in the dump, not just the
 * verdict.
 *
 * ```bash
 * composer test:agents
 * ```
 */
#[Group('agents')]
final class LegalThesisResearchTest extends TestCase
{
    #[Test]
    public function it_researches_the_theses_a_tax_enforcement_defence_can_argue(): void
    {
        $facts = <<<'TXT'
        A Fazenda Pública do Estado ajuizou execução fiscal contra a minha empresa,
        uma transportadora que foi encerrada e baixada regularmente na Junta
        Comercial em março de 2019, com distrato registrado e baixa no CNPJ. A
        dívida é de ICMS declarado e não pago entre 2017 e 2018.

        Agora, em 2025, a Fazenda pediu ao juiz que a execução fosse redirecionada
        contra mim, que era o sócio-administrador, alegando só que o imposto não
        foi pago. Não houve nenhuma fraude, nenhum desvio, nenhuma dissolução
        irregular: a empresa fechou com tudo em ordem e comuniquei a baixa aos
        órgãos.

        Fui citado e ainda não garanti o juízo, porque não tenho como depositar o
        valor. Quero me defender e sair dessa execução.
        TXT;

        $legalCase = $this->pleading(
            new PracticeArea(['slug' => 'tributario', 'label' => 'Direito Tributário']),
            new ProceduralClass(['code' => 1118, 'name' => 'Embargos à Execução Fiscal']),
            ['facts' => $facts, 'court_addressing' => 'Vara de Execuções Fiscais da Comarca de Curitiba/PR'],
            [
                new Requirement(['description' => 'A exclusão do Autor do polo passivo da execução fiscal;', 'amount' => null]),
                new Requirement(['description' => 'A extinção da execução fiscal quanto ao Autor;', 'amount' => null]),
            ],
        );

        $research = ResearchLegalCaseTheses::run($legalCase);

        $this->show($facts, $research);

        // O contrato do payload. Um rename tem de estourar aqui.
        $payload = $research->toArray();
        $this->assertSame(
            ['legal_question', 'theses', 'precedents', 'sources', 'pending', 'unverified_citations'],
            array_keys($payload),
        );

        // A guarda, que não depende de modelo nenhum: tudo o que sobreviveu tem
        // procedência oficial, porque o que não tinha foi removido.
        $this->assertGuardHolds($research);

        // Os tetos são de código, não de gramática.
        $this->assertLessThanOrEqual(LegalResearchData::MAX_THESES, count($research->review->theses));

        foreach ($research->review->theses as $thesis) {
            $this->assertLessThanOrEqual(LegalResearchData::MAX_LEGAL_BASES, count($thesis->legalBases));
            $this->assertNotSame('', $thesis->name);
            $this->assertNotSame('', $thesis->description);

            // O id é a chave de correlação cunhada em PHP, e é o que os
            // precedentes citam. Sem ele o vínculo aterra em null no save.
            $this->assertNotNull($thesis->id);
        }

        // Todo precedente aponta para uma tese desta mesma resposta — é o que a
        // aninhamento do schema comprou, e o achatamento tem de preservar.
        $ids = array_map(static fn ($thesis): ?string => $thesis->id, $research->review->theses);

        foreach ($research->review->precedents as $precedent) {
            $this->assertContains($precedent->thesisId, $ids, 'Um precedente ficou órfão no achatamento.');
            $this->assertNull($precedent->id, 'O id de um precedente novo é cunhado pelo banco, nunca pelo agente.');
        }

        // A frase de não confirmação é um sinal, nunca um valor.
        foreach ($research->review->theses as $thesis) {
            $this->assertStringNotContainsStringIgnoringCase('não localizado', $thesis->name);
        }
    }

    /**
     * A narrativa que não sustenta pesquisa nenhuma.
     *
     * O que se exige aqui não é uma lista vazia — o agente pode muito bem achar
     * uma tese de direito do consumidor — e sim que a resposta continue sendo
     * uma resposta: sem exceção, sem citação sem fonte, e com o que ficou em
     * aberto dito em `pending` em vez de preenchido no chute.
     */
    #[Test]
    public function it_comes_back_empty_handed_rather_than_inventing(): void
    {
        $facts = 'Comprei um liquidificador e ele veio com um risco na jarra. Fiquei chateado.';

        $legalCase = $this->pleading(
            new PracticeArea(['slug' => 'consumidor', 'label' => 'Direito do Consumidor']),
            new ProceduralClass(['code' => 283, 'name' => 'Procedimento Comum Cível']),
            ['facts' => $facts],
            [],
        );

        $research = ResearchLegalCaseTheses::run($legalCase);

        $this->show($facts, $research);

        $this->assertGuardHolds($research);
        $this->assertLessThanOrEqual(LegalResearchData::MAX_THESES, count($research->review->theses));
    }

    /**
     * Nenhuma citação sobrevive sem uma fonte oficial por trás.
     *
     * Vale para `legal_bases` e para os precedentes, e é a asserção que tem de
     * passar com qualquer modelo em qualquer dia: quem a faz passar é
     * LegalResearchData, e não o agente. Se esta ficar vermelha, o lugar de
     * olhar é a guarda, não o prompt.
     */
    private function assertGuardHolds(LegalResearchData $research): void
    {
        foreach ($research->sources as $source) {
            $this->assertTrue(
                OfficialLegalSources::covers($source),
                "Uma fonte não oficial passou pela guarda: {$source}",
            );
        }

        foreach ($research->review->theses as $thesis) {
            foreach ($thesis->legalBases as $basis) {
                $this->assertNotSame('', $basis->reference);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<Requirement>  $requirements
     */
    private function pleading(
        PracticeArea $area,
        ProceduralClass $class,
        array $attributes,
        array $requirements,
    ): LegalCase {
        $legalCase = new LegalCase($attributes);

        // Três relações e não quatro: a pesquisa não recebe o cliente nem o
        // réu, e `forResearch()` não os lê. Defini-las seria dizer que ele os vê.
        $legalCase->setRelation('practiceArea', $area);
        $legalCase->setRelation('proceduralClass', $class);
        $legalCase->setRelation('requirements', new Collection($requirements));

        return $legalCase;
    }

    private function show(string $facts, LegalResearchData $research): void
    {
        // The point of this test is to look at the answer, and PHPUnit swallows
        // stdout — STDERR is what actually reaches the terminal.
        fwrite(STDERR, PHP_EOL.json_encode([
            'fatos' => $facts,
            'resposta' => $research->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL.PHP_EOL);
    }
}
