<?php

declare(strict_types=1);

namespace App\Ai\Concerns;

use Laravel\Ai\Enums\Lab;

/**
 * Declares the context window an agent's prompt is allowed to fill.
 *
 * Ollama truncates a prompt that overruns the window without saying so: the
 * answer comes back plausible, built on a system prompt that lost its tail.
 * Left unset, the daemon's default decides it — and our prompts are large.
 *
 * The number itself lives in `config/ai.php`, because it describes the prompts
 * this project writes and not the model reading them. That is what makes
 * swapping models a one-line env change: an agent using this trait asks for
 * the same window whoever is answering, and every model worth running here
 * clears it several times over.
 *
 * `num_ctx` is Ollama's spelling of the idea, and the only spelling there is:
 * another driver gets nothing rather than an option it would reject — the
 * option list reaches the wire as the request's `options` key, unvalidated.
 *
 * Which means that under Gemini, the provider the agents point at today, this
 * trait sends nothing at all. That is correct and not a gap: Gemini's window
 * is orders of magnitude larger than anything we build, and it answers an
 * overlong prompt with an error instead of a quiet truncation. The trait stays
 * on every agent because the truncation it guards against is one uncommented
 * `#[Provider('ollama')]` away.
 */
trait UsesConfiguredContextWindow
{
    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $lab = $provider instanceof Lab ? $provider : Lab::tryFrom($provider);

        if ($lab !== Lab::Ollama) {
            return [];
        }

        return ['num_ctx' => (int) config('ai.context_window')];
    }
}
