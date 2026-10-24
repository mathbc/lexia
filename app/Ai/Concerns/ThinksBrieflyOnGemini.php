<?php

declare(strict_types=1);

namespace App\Ai\Concerns;

use Laravel\Ai\Enums\Lab;

/**
 * Ollama's runtime knobs, plus a short reasoning on the Gemini — for the agents
 * that read and choose, and neither research nor compose.
 *
 * ConfiguresOllamaRuntime sends nothing to a Gemini default, so the Gemini
 * thinks at its own level, and for the two agents of the themes tab that was
 * nearly all of the tab's time. Measured on 2026-10-24, one pleading (a traffic
 * accident), on `gemini-3.7-flash`:
 *
 * | thinking | formulation | selection | the tab |
 * |---|---|---|---|
 * | default | 9.1 s, 696 reasoning tokens | 12.5 s, 1,223 | 22.4 s |
 * | `low` | 3.7 s, 0 | 4.2 s, 0 | 8.6 s |
 *
 * The same three themes (440, 834, 469) in the same order, and five questions
 * on the same five issues. The embedding and the vector search were 0.7 s of
 * either run. It is ConfiguresOllamaRuntime's `think` finding again, on another
 * provider: more reasoning was not buying a different answer.
 *
 * Opt-in, and not the default for every agent: the researchers open portals
 * inside the same request and were not measured at `low`, and the agents that
 * write prose turn the reasoning *up* on Ollama, for a reason that holds here.
 *
 * `thinkingLevel` is Gemini 3's spelling. A 1.x or 2.x default would refuse the
 * key — 2.5 speaks `thinkingBudget`, the older ones do not think —, so for them
 * this sends nothing and the model keeps its own default.
 */
trait ThinksBrieflyOnGemini
{
    use ConfiguresOllamaRuntime {
        providerOptions as ollamaRuntimeOptions;
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $lab = $provider instanceof Lab ? $provider : Lab::tryFrom($provider);

        if ($lab !== Lab::Gemini) {
            return $this->ollamaRuntimeOptions($provider);
        }

        return preg_match('/^gemini-[12]\./', $this->model()) === 1
            ? []
            : ['thinkingConfig' => ['thinkingLevel' => 'low']];
    }
}
