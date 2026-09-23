<?php

declare(strict_types=1);

namespace Tests\Feature\LegalPleadings;

use App\Ai\Agents\PleadingDraftingAgent;
use App\Domain\CourtDecisions\Models\CourtDecision;
use App\Domain\LegalCases\Actions\DraftLegalPleading;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalPleadings\Support\PleadingBlocks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Prompts\AgentPrompt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The seventh step's rulings, from the table to the stored draft.
 *
 * The agent is faked: what is pinned is the Action around it — that the rulings
 * the lawyer kept reach the dossier under their markers, and that each marker
 * the agent placed comes back, in the version that is stored, as the ementa and
 * its reference in two indented paragraphs. Whether the model places them where
 * they belong is measured in tests/Agents, where it costs inference.
 */
final class DraftLegalPleadingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_kept_rulings_are_quoted_where_the_agent_placed_their_markers(): void
    {
        $case = LegalCase::factory()->finalised()->create([
            'facts' => 'O credor penhorou bens do Município para garantir a execução da sentença.',
        ]);

        // Desmarcado na etapa 7: o "Concluir" o apaga antes de redigir, e os
        // marcadores numeram a lista que ficou — não a que a pesquisa trouxe.
        CourtDecision::factory()->forLegalCase($case)->nth(0)->create()->delete();
        $kept = CourtDecision::factory()->forLegalCase($case)->nth(1)->create();

        PleadingDraftingAgent::fake([[
            'content' => implode("\n\n", [
                'III – DO DIREITO',
                'Os embargos à execução são o instrumento para impugnar a penhora.',
                'Nesse sentido, é o entendimento do Superior Tribunal de Justiça:',
                '[[JULGADO 1]]',
                'IV – DOS PEDIDOS E REQUERIMENTOS',
                'Nestes termos, pede deferimento.',
            ]),
        ]]);

        $pleading = DraftLegalPleading::run($case);

        PleadingDraftingAgent::assertPrompted(static function (AgentPrompt $prompt): bool {
            $instructions = (string) $prompt->agent->instructions();

            return str_contains($instructions, '- Marcador: [[JULGADO 1]]'.PHP_EOL.'- Tribunal: Superior Tribunal de Justiça. 6ª Turma')
                && ! str_contains($instructions, '[[JULGADO 2]]');
        });

        $citations = array_values(array_filter(
            PleadingBlocks::of($pleading->content),
            static fn (array $block): bool => $block['citation'],
        ));

        // A ementa copiada do registro, e não da resposta do modelo, e a
        // referência composta das colunas — os dois recuados.
        $this->assertSame([
            ['"'.$kept->summary.'"'],
            ['(BRASIL. Superior Tribunal de Justiça. REsp 143513 / SP. 6ª Turma, julgado em 28/04/1998.)'],
        ], array_column($citations, 'lines'));

        $this->assertStringNotContainsString('JULGADO', $pleading->content);
        $this->assertStringNotContainsString('Supremo Tribunal Federal', $pleading->content);

        // A citação fica onde o marcador estava: dentro de DO DIREITO, antes
        // dos pedidos.
        $this->assertLessThan(
            mb_strpos($pleading->content, 'IV – DOS PEDIDOS'),
            mb_strpos($pleading->content, $kept->summary),
        );
    }
}
