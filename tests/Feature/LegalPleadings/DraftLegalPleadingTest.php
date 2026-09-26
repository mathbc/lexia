<?php

declare(strict_types=1);

namespace Tests\Feature\LegalPleadings;

use App\Ai\Agents\PleadingDraftingAgent;
use App\Ai\Agents\PleadingGroundsReinforcementAgent;
use App\Domain\CourtDecisions\Models\CourtDecision;
use App\Domain\LegalCases\Actions\DraftLegalPleading;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalPleadings\Models\LegalPleading;
use App\Domain\LegalPleadings\Support\PleadingBlocks;
use App\Domain\LegalTheses\Models\LegalThesis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Prompts\AgentPrompt;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The seventh step's rulings, from the table to the stored draft.
 *
 * The agents are faked: what is pinned is the Action around them — that the
 * rulings the lawyer kept reach the dossier under their markers, that each
 * marker the agent placed comes back, in the version that is stored, as the
 * ementa (abridged to the passages chosen) and its reference in two indented
 * paragraphs, and that the second agent's DO DIREITO replaces the first one's
 * only when it keeps every ruling — falling back, never failing, when it does
 * not. Whether the models write well is measured in tests/Agents, where it
 * costs inference.
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
            'excerpts' => [],
        ]]);
        PleadingGroundsReinforcementAgent::fake([]);

        $pleading = DraftLegalPleading::run($case);

        // Sem tese não há com o que embasar, e o segundo agente nem é chamado.
        PleadingGroundsReinforcementAgent::assertNeverPrompted();

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

    /**
     * O embasamento: o corpo do DO DIREITO vai ao segundo agente com as teses
     * inteiras no dossiê, e o que ele devolve é o que fica na versão gravada —
     * sob o título que a redação escreveu, com o julgado ainda citado.
     */
    #[Test]
    public function the_grounds_are_reinforced_from_the_theses_before_the_version_is_stored(): void
    {
        [$case, $thesis] = $this->caseWithThesisAndRuling();

        PleadingDraftingAgent::fake([$this->draft($thesis, 'A matéria é de ordem pública.')]);
        PleadingGroundsReinforcementAgent::fake([[
            'content' => implode("\n\n", [
                $thesis->name,
                'A exceção de pré-executividade cabe sem garantia do juízo porque a matéria é de ordem pública e não depende de prova, como assenta a Súmula 393 do STJ.',
                'Nesse sentido, é o entendimento do Superior Tribunal de Justiça, que admite a impugnação da penhora pelos embargos:',
                '[[JULGADO 1]]',
            ]),
        ]]);

        $pleading = DraftLegalPleading::run($case);

        PleadingGroundsReinforcementAgent::assertPrompted(static function (AgentPrompt $prompt) use ($thesis): bool {
            $instructions = (string) $prompt->agent->instructions();

            // O prompt é o corpo, sem o título — a numeração não é do agente.
            return str_starts_with($prompt->prompt, $thesis->name)
                && ! str_contains($prompt->prompt, 'DO DIREITO')
                && str_contains($instructions, '- Espécie: Preliminar')
                && str_contains($instructions, '  - Súmula 393 do STJ (Súmula — STJ)')
                && str_contains($instructions, '- Marcador: [[JULGADO 1]]');
        });

        $grounds = $this->between($pleading, 'II – DO DIREITO', 'III – DOS PEDIDOS');

        $this->assertStringContainsString('como assenta a Súmula 393 do STJ', $grounds);
        $this->assertStringNotContainsString('A matéria é de ordem pública.', $grounds);
        $this->assertStringNotContainsString('JULGADO', $pleading->content);
        $this->assertCount(2, $this->citations($pleading));
    }

    /**
     * Um reforço que perde o julgado que o advogado escolheu é recusado, e a
     * versão gravada fica com o DO DIREITO da redação — que ainda o cita.
     */
    #[Test]
    public function a_reinforcement_that_drops_a_ruling_is_discarded(): void
    {
        [$case, $thesis] = $this->caseWithThesisAndRuling();

        PleadingDraftingAgent::fake([$this->draft($thesis, 'A matéria é de ordem pública.')]);
        PleadingGroundsReinforcementAgent::fake([[
            'content' => $thesis->name."\n\nUm argumento mais longo, sem o julgado.",
        ]]);

        $pleading = DraftLegalPleading::run($case);

        $grounds = $this->between($pleading, 'II – DO DIREITO', 'III – DOS PEDIDOS');

        $this->assertStringContainsString('A matéria é de ordem pública.', $grounds);
        $this->assertStringNotContainsString('sem o julgado', $pleading->content);
        $this->assertCount(2, $this->citations($pleading));
    }

    /**
     * O segundo agente é o único passo que pode falhar sozinho, e a falha
     * custa o reforço, nunca a minuta.
     */
    #[Test]
    public function a_reinforcement_that_fails_does_not_cost_the_pleading(): void
    {
        [$case, $thesis] = $this->caseWithThesisAndRuling();

        PleadingDraftingAgent::fake([$this->draft($thesis, 'A matéria é de ordem pública.')]);
        PleadingGroundsReinforcementAgent::fake(static fn (): never => throw new RuntimeException('Cota esgotada.'));

        $pleading = DraftLegalPleading::run($case);

        $this->assertSame(1, $pleading->version);
        $this->assertStringContainsString('A matéria é de ordem pública.', $pleading->content);
        $this->assertCount(2, $this->citations($pleading));
    }

    /**
     * Os trechos que o agente escolheu abreviam a citação na minuta — o
     * cabeçalho fica, cada corte vira `[...]` e a referência sai inteira —, e
     * só na minuta: o julgado gravado continua com a ementa completa, que é o
     * que a etapa 7 mostra.
     */
    #[Test]
    public function the_chosen_passages_abridge_the_quotation_and_only_the_quotation(): void
    {
        $case = LegalCase::factory()->finalised()->create([
            'facts' => 'O credor penhorou bens do Município para garantir a execução da sentença.',
        ]);
        $kept = CourtDecision::factory()->forLegalCase($case)->nth(1)->create();
        $summary = $kept->summary;

        $passage = '- Em sede de execução de sentença, insurgindo-se o devedor contra a penhora imposta sobre seus bens, deve fazê-lo por meio do instrumento processual nominado dos embargos à execução, sendo irrelevante a circunstância de se tratarem de bens impenhoráveis de entidade pública.';

        // O terceiro trecho, como o dossiê o numera: cabeçalho, o primeiro
        // travessão, e este.
        PleadingDraftingAgent::fake([[
            'content' => "III – DO DIREITO\n\nNesse sentido:\n\n[[JULGADO 1]]\n\nIV – DOS PEDIDOS\n\nNestes termos, pede deferimento.",
            'excerpts' => [['ruling' => 1, 'passages' => [3]]],
        ]]);
        PleadingGroundsReinforcementAgent::fake([]);

        $pleading = DraftLegalPleading::run($case);

        PleadingDraftingAgent::assertPrompted(static fn (AgentPrompt $prompt): bool => str_contains(
            (string) $prompt->agent->instructions(),
            '  Trecho 3: '.$passage,
        ));

        $this->assertSame([
            ['"PROCESSUAL CIVIL. EMBARGOS À EXECUÇÃO. PENHORA. NATUREZA IMPENHORÁVEL DOS BENS PÚBLICOS. CABIMENTO. [...] '.$passage.' [...]"'],
            ['(BRASIL. Superior Tribunal de Justiça. REsp 143513 / SP. 6ª Turma, julgado em 28/04/1998.)'],
        ], array_column($this->citations($pleading), 'lines'));

        $this->assertSame($summary, $kept->fresh()?->summary);
    }

    /**
     * Uma peça com uma tese e um julgado mantido, como a etapa 7 a deixa.
     *
     * @return array{LegalCase, LegalThesis}
     */
    private function caseWithThesisAndRuling(): array
    {
        $case = LegalCase::factory()->finalised()->create([
            'facts' => 'A Fazenda redirecionou a execução fiscal ao sócio sem prova de dissolução irregular.',
        ]);

        $thesis = LegalThesis::factory()->forLegalCase($case)->nth(0)->create();
        CourtDecision::factory()->forLegalCase($case)->nth(1)->create();

        return [$case, $thesis];
    }

    /**
     * A resposta do agente de minuta: uma subseção por tese, e o julgado no fim.
     *
     * @return array<string, mixed>
     */
    private function draft(LegalThesis $thesis, string $argument): array
    {
        return [
            'content' => implode("\n\n", [
                'EXCELENTÍSSIMO SENHOR DOUTOR JUIZ DE DIREITO DA VARA DE EXECUÇÕES FISCAIS',
                'I – DOS FATOS',
                'A Fazenda redirecionou a execução.',
                'II – DO DIREITO',
                $thesis->name,
                $argument,
                'Nesse sentido, é o entendimento do Superior Tribunal de Justiça:',
                '[[JULGADO 1]]',
                'III – DOS PEDIDOS E REQUERIMENTOS',
                'Nestes termos, pede deferimento.',
            ]),
            'excerpts' => [],
        ];
    }

    private function between(LegalPleading $pleading, string $from, string $to): string
    {
        $start = mb_strpos($pleading->content, $from);
        $end = mb_strpos($pleading->content, $to);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return mb_substr($pleading->content, $start, $end - $start);
    }

    /**
     * @return list<array{lines: list<string>, citation: bool}>
     */
    private function citations(LegalPleading $pleading): array
    {
        return array_values(array_filter(
            PleadingBlocks::of($pleading->content),
            static fn (array $block): bool => $block['citation'],
        ));
    }
}
