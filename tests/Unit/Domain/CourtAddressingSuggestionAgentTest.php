<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Ai\Agents\CourtAddressingSuggestionAgent;
use App\Ai\Agents\JudicialSystemSelectionAgent;
use App\Domain\LegalCases\Enums\CourtDivision;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shape of the two addressing answers and the order of their prompts,
 * asserted where it costs nothing. Whether the agent reads competence well is
 * the business of tests/Agents/CourtAddressingSuggestionTest, against the real
 * model.
 */
final class CourtAddressingSuggestionAgentTest extends TestCase
{
    /**
     * O juízo só pode ser um dos que a classe admite — e o nulo, que é a
     * resposta para o foro que não é de primeiro grau.
     */
    #[Test]
    public function the_court_is_one_the_class_allows_or_null(): void
    {
        $division = $this->schema()['division']->toArray();

        $this->assertSame(['civil', 'family', null], $division['enum']);
        $this->assertContains('null', (array) $division['type']);
    }

    #[Test]
    public function the_source_and_the_state_are_closed_lists_with_null(): void
    {
        $schema = $this->schema();

        $this->assertSame(['plaintiff_address', 'defendant_address', 'facts', null], $schema['forum_source']->toArray()['enum']);
        $this->assertSame(['SC', 'SP', null], $schema['state']->toArray()['enum']);
    }

    /**
     * Toda parte existe mesmo quando é nula, e a justificativa é a única que
     * não pode ser.
     */
    #[Test]
    public function every_part_is_required_and_only_the_justification_is_never_null(): void
    {
        $schema = $this->schema();

        foreach (['division', 'forum_source', 'city', 'state', 'legal_basis'] as $part) {
            $this->assertContains('null', (array) $schema[$part]->toArray()['type'], "{$part} não aceita nulo.");
        }

        $this->assertSame('string', $schema['justification']->toArray()['type']);
    }

    /**
     * O que varia vai no fim, pelo cache de prefixo: a área, a classe, as
     * partes e os juízos que a classe admite.
     */
    #[Test]
    public function the_case_the_parties_and_the_courts_close_the_instructions(): void
    {
        $instructions = $this->agent()->instructions();

        $this->assertStringContainsString('# Base de conhecimento'.PHP_EOL.PHP_EOL.'O guia.', $instructions);
        $this->assertStringContainsString('A área de atuação deste caso é **Direito do Consumidor**.', $instructions);
        $this->assertStringContainsString('- Autor (o cliente): pessoa física; domicílio em Joinville/SC.', $instructions);
        $this->assertStringEndsWith(
            '- `civil` — Vara Cível (Justiça Estadual)'.PHP_EOL.'- `family` — Vara de Família e Sucessões (Justiça Estadual)',
            $instructions,
        );
        $this->assertLessThan(
            strpos($instructions, '# A peça'),
            strpos($instructions, '# Base de conhecimento'),
        );
    }

    /**
     * O segundo agente só escolhe entre os sistemas do tribunal do foro, pelo
     * slug — o uuid difere entre bancos.
     */
    #[Test]
    public function the_system_is_one_of_the_courts_candidates(): void
    {
        $agent = new JudicialSystemSelectionAgent(
            forum: 'Juízo: Vara Cível (Justiça Estadual); estado do foro: São Paulo; cidade do foro: Campinas.',
            candidates: [
                ['slug' => 'esaj', 'line' => '`esaj` — e-SAJ (TJSP, em uso)'],
                ['slug' => 'eproc', 'line' => '`eproc` — eproc (TJSP, em uso)'],
            ],
            areaLabel: 'Direito do Consumidor',
            classLine: null,
            parties: ['Autor (o cliente): pessoa física; domicílio em Campinas/SP.'],
        );

        $system = $agent->schema(new JsonSchemaTypeFactory)['system']->toArray();

        $this->assertSame(['esaj', 'eproc'], $system['enum']);
        $this->assertSame('string', $system['type']);
        $this->assertStringEndsWith('- `esaj` — e-SAJ (TJSP, em uso)'.PHP_EOL.'- `eproc` — eproc (TJSP, em uso)', $agent->instructions());
    }

    /**
     * @return array<string, Type>
     */
    private function schema(): array
    {
        return $this->agent()->schema(new JsonSchemaTypeFactory);
    }

    private function agent(): CourtAddressingSuggestionAgent
    {
        return new CourtAddressingSuggestionAgent(
            areaLabel: 'Direito do Consumidor',
            classLine: '[7] Procedimento Comum Cível — O rito residual.',
            divisions: [CourtDivision::Civil, CourtDivision::Family],
            states: ['SC', 'SP'],
            parties: [
                'Autor (o cliente): pessoa física; domicílio em Joinville/SC.',
                'Réu: pessoa jurídica, Loja Exemplo Ltda; endereço em São Paulo/SP.',
            ],
            knowledge: 'O guia.',
        );
    }
}
