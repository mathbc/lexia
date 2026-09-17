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
 * `num_ctx` is Ollama's spelling of the idea. Another driver gets nothing
 * rather than an option it would reject — the option list reaches the wire as
 * the request's `options` key, unvalidated.
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
