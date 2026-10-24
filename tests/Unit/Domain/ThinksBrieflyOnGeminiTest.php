<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Ai\Agents\LegalQuestionFormulationAgent;
use App\Ai\Agents\LegalThemeSelectionAgent;
use App\Domain\AiModels\Enums\AiProvider;
use App\Domain\AiModels\Models\AiModel;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Os dois agentes da aba de temas pensam pouco no Gemini — era quase todo o
 * tempo da aba, e não mudava a resposta (ver ThinksBrieflyOnGemini) — e
 * continuam mandando ao Ollama exatamente o que mandavam.
 */
final class ThinksBrieflyOnGeminiTest extends TestCase
{
    #[Test]
    public function the_theme_agents_ask_a_gemini_3_default_for_a_short_reasoning(): void
    {
        foreach ($this->agents() as $agent) {
            $this->assertSame(['thinkingConfig' => ['thinkingLevel' => 'low']], $agent->providerOptions(Lab::Gemini));
            $this->assertSame(['thinkingConfig' => ['thinkingLevel' => 'low']], $agent->providerOptions('gemini'));
        }
    }

    /**
     * `thinkingLevel` é a grafia do Gemini 3; um padrão mais antigo recusaria
     * a chave, e a requisição inteira com ela.
     */
    #[Test]
    public function an_older_gemini_default_gets_no_thinking_key(): void
    {
        $this->usingDefaultModels(new AiModel(['provider' => AiProvider::Gemini, 'name' => 'gemini-2.5-flash', 'api_key' => 'test-key']));

        foreach ($this->agents() as $agent) {
            $this->assertSame([], $agent->providerOptions(Lab::Gemini));
        }
    }

    #[Test]
    public function an_ollama_default_still_gets_the_ollama_runtime(): void
    {
        foreach ($this->agents() as $agent) {
            $this->assertSame([
                'num_ctx' => (int) config('ai.context_window'),
                'keep_alive' => config('ai.runtime.keep_alive'),
                'think' => config('ai.runtime.reasoning_effort'),
            ], $agent->providerOptions(Lab::Ollama));
        }
    }

    /**
     * @return list<HasProviderOptions>
     */
    private function agents(): array
    {
        return [
            new LegalQuestionFormulationAgent('Área: Cível'),
            new LegalThemeSelectionAgent(framing: 'Área: Cível', questions: [], candidates: []),
        ];
    }
}
