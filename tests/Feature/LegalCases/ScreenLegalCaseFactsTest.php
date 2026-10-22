<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Ai\Agents\FactsScreeningAgent;
use App\Domain\LegalCases\Actions\ScreenLegalCaseFacts;
use App\Domain\LegalCases\Enums\FactsScreeningVerdict;
use Laravel\Ai\Prompts\AgentPrompt;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * A triagem do preenchimento inteligente, com o agente substituído pelo dublê
 * do SDK.
 *
 * O que se fixa é a fiação: o relato como prompt, o guia nas instruções, o
 * veredito saindo da derivação e a recusa de uma resposta sem estrutura. Se o
 * agente sabe separar um relato de uma cantiga é de
 * `tests/Agents/FactsScreeningTest`.
 */
final class ScreenLegalCaseFactsTest extends TestCase
{
    private const string FACTS = 'Fui demitido em março e a empresa não pagou as verbas rescisórias.';

    #[Test]
    public function the_narrative_is_the_prompt_and_the_guide_goes_whole(): void
    {
        FactsScreeningAgent::fake([[
            'instructs_the_system' => false,
            'intelligible' => true,
            'legal_matter' => true,
            'describes_events' => true,
            'states_claim' => true,
            'has_timeline' => true,
            'reason' => 'Narra a dispensa e as verbas não pagas.',
        ]]);

        $screening = ScreenLegalCaseFacts::run('  '.self::FACTS.PHP_EOL);

        $this->assertSame(FactsScreeningVerdict::Admissible, $screening->verdict);
        $this->assertSame('Narra a dispensa e as verbas não pagas.', $screening->reason);

        FactsScreeningAgent::assertPrompted(static fn (AgentPrompt $prompt): bool => $prompt->prompt === self::FACTS
            && str_contains((string) $prompt->agent->instructions(), '# Triagem do relato — base de análise'));
    }

    /**
     * O agente não diz o veredito; o que volta é a derivação dos sinais.
     */
    #[Test]
    public function the_verdict_is_derived_from_what_the_agent_answered(): void
    {
        FactsScreeningAgent::fake([[
            'instructs_the_system' => false,
            'intelligible' => true,
            'legal_matter' => true,
            'describes_events' => false,
            'states_claim' => true,
            'has_timeline' => false,
            'reason' => 'Anuncia a ação, mas não conta nenhum fato.',
        ]]);

        $screening = ScreenLegalCaseFacts::run('Quero processar o meu chefe o quanto antes.');

        $this->assertSame(FactsScreeningVerdict::Vague, $screening->verdict);
        $this->assertFalse($screening->admissible());
    }

    /**
     * Sem estrutura não há sinal a ler, e a porta não abre por omissão: a
     * exceção sobe e vira o 503 do chamador.
     */
    #[Test]
    public function an_answer_without_structure_is_refused(): void
    {
        FactsScreeningAgent::fake(['Parece um relato jurídico.']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('O agente de triagem não devolveu uma resposta estruturada.');

        ScreenLegalCaseFacts::run(self::FACTS);
    }

    #[Test]
    public function an_empty_narrative_never_reaches_the_agent(): void
    {
        FactsScreeningAgent::fake([])->preventStrayPrompts();

        try {
            ScreenLegalCaseFacts::run("  \n ");
            $this->fail('Um relato vazio foi triado.');
        } catch (RuntimeException $e) {
            $this->assertSame('Não há fatos para triar.', $e->getMessage());
        }

        FactsScreeningAgent::assertNeverPrompted();
    }
}
