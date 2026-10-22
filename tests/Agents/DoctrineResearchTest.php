<?php

declare(strict_types=1);

namespace Tests\Agents;

use App\Domain\Doctrines\Data\DoctrineData;
use App\Domain\Doctrines\Support\DoctrineSources;
use App\Domain\LegalCases\Actions\ResearchLegalCaseDoctrines;
use App\Domain\LegalCases\Data\DoctrineResearchData;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The doctrine research pair against the real provider, no fakes.
 *
 * CourtDecisionResearchTest's sibling, with its caveats: it spends Gemini quota
 * and depends on the LexML being up, so red here is not automatically a prompt
 * regression. Budget minutes — two serial `Timeout(360)` and then up to seven
 * record reads.
 *
 * **Everything this file asserts that does not depend on the model is also
 * asserted, without a network, in `tests/Unit/Domain/DoctrineResearchDataTest`
 * and `DoctrineRecordReaderTest`.** Trust those when this one is red for
 * reasons outside the code.
 *
 * No RefreshDatabase: the pleading is built in memory with its two relations
 * set by hand, which is all `LegalCaseDossier::forDoctrines()` reads.
 *
 * ## What is pinned
 *
 * Which works answer a case is judgement, and is not pinned. What is: the
 * ceiling of five; that every surviving work is a library record with an author
 * (the guard, which holds for any model on any day); that the agent condensed
 * the case into search terms; and that no two rows are the same work.
 *
 * ## What to watch in the dump
 *
 * The search terms — short, on the substantive institute, never the class
 * description pasted whole — and the `relevance` notes, which must speak of the
 * *subject* of each work and never of what its author "defende". No assertion
 * catches that reliably; read it.
 *
 * ```bash
 * composer test:agents -- --filter=DoctrineResearchTest
 * ```
 */
#[Group('agents')]
final class DoctrineResearchTest extends TestCase
{
    #[Test]
    public function it_finds_the_scholarship_on_a_carriers_liability_for_robbery(): void
    {
        $facts = <<<'TXT'
        Eu estava num ônibus intermunicipal da empresa Viação Litoral quando dois
        homens armados anunciaram um assalto na rodovia. Levaram meu celular, minha
        carteira e meu notebook de trabalho. O motorista disse que aquele trecho é
        conhecido pelos assaltos e que a empresa nunca pôs segurança nem câmeras.
        Fiquei abalado e perdi dois dias de trabalho. Quero que a empresa me
        indenize pelos bens e pelo abalo.
        TXT;

        $legalCase = $this->pleading(
            new PracticeArea(['slug' => 'consumidor', 'label' => 'Direito do Consumidor']),
            new ProceduralClass([
                'code' => 7,
                'name' => 'Procedimento Comum Cível',
                'description' => 'Rito comum do processo de conhecimento, residual para toda pretensão sem procedimento especial.',
            ]),
            ['facts' => $facts],
        );

        $research = ResearchLegalCaseDoctrines::run($legalCase);

        $this->show($facts, $research);

        $this->assertNotEmpty($research->doctrines, 'Nenhuma obra sobreviveu à leitura dos registros.');
        $this->assertLessThanOrEqual(DoctrineResearchData::WANTED, count($research->doctrines));

        // Os termos existem e são curtos: a descrição da classe não foi colada.
        $this->assertNotEmpty($research->searchTerms);

        foreach ($research->searchTerms as $term) {
            $words = preg_split('/\s+/u', trim($term)) ?: [];

            $this->assertLessThanOrEqual(8, count($words), "Termo de busca longo demais: {$term}");
        }

        $this->assertGuardHolds($research);

        $urls = array_map(static fn (DoctrineData $doctrine): ?string => $doctrine->sourceUrl, $research->doctrines);

        $this->assertSameSize($urls, array_unique($urls), 'O agente repetiu a mesma obra.');
    }

    /**
     * Toda obra que sobreviveu é um registro da rede de bibliotecas, com autor.
     * Quem garante é o PHP, não o agente: vermelho aqui é a guarda.
     */
    private function assertGuardHolds(DoctrineResearchData $research): void
    {
        foreach ($research->sources as $source) {
            $this->assertTrue(DoctrineSources::isRecord($source), "Fonte fora do acervo: {$source}");
        }

        foreach ($research->doctrines as $doctrine) {
            $this->assertTrue(DoctrineSources::isRecord($doctrine->sourceUrl), "Obra sem registro: {$doctrine->title}");
            $this->assertNotSame('', $doctrine->author, "Obra sem autor: {$doctrine->title}");
            $this->assertNull($doctrine->id);
            $this->assertStringNotContainsStringIgnoringCase('não localizado', $doctrine->title);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function pleading(PracticeArea $area, ProceduralClass $class, array $attributes): LegalCase
    {
        $legalCase = new LegalCase($attributes);

        $legalCase->setRelation('practiceArea', $area);
        $legalCase->setRelation('proceduralClass', $class);

        return $legalCase;
    }

    private function show(string $facts, DoctrineResearchData $research): void
    {
        fwrite(STDERR, PHP_EOL.json_encode([
            'fatos' => $facts,
            'doutrinas' => array_map(static fn (DoctrineData $doctrine): array => $doctrine->toArray(), $research->doctrines),
            'relato' => $research->findings(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL.PHP_EOL);
    }
}
