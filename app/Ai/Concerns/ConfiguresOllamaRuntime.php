<?php

declare(strict_types=1);

namespace App\Ai\Concerns;

use Illuminate\Support\Arr;
use Laravel\Ai\Enums\Lab;

/**
 * The three Ollama runtime knobs every agent in this project turns.
 *
 * Formerly UsesConfiguredContextWindow, which named one of them. It grew the
 * other two once the local path was measured, and the old name would have hidden
 * the one that matters most.
 *
 * All three are Ollama's spelling and no one else's, so another driver gets an
 * empty array rather than options it would reject — the list reaches the wire as
 * the request's `options` key, unvalidated, except for the handful the gateway
 * hoists to the top level (`BuildsTextRequests`, `$topLevelKeys`), which is how
 * `keep_alive` and `think` get where they belong.
 *
 * ## `num_ctx` — the window the prompt is allowed to fill
 *
 * Ollama truncates an overrunning prompt without saying so: the answer comes
 * back plausible, built on a system prompt that lost its tail. The number lives
 * in `config/ai.php` because it describes the prompts this project writes and
 * not the model reading them.
 *
 * It is deliberately **uniform across agents**. Ollama keys the loaded runner by
 * `num_ctx`, so a per-agent value would force a model reload between agents —
 * the footprint is set by the worst prompt, which is correct.
 *
 * ## `keep_alive` — how long the model stays resident
 *
 * The daemon's default is five minutes. Every pause longer than that charges the
 * next request a reload of a 12.8 GB model, which is pure latency in
 * development and in any production with sparse traffic.
 *
 * ## `think` — the lever that dominates everything else
 *
 * Decode is ~99% of a local inference here, and the reasoning is most of the
 * decode. Measured on `gpt-oss:20b` with RequirementExtractionAgent's real
 * prompt: the default spent 42,239 characters of reasoning across 156 seconds
 * and returned an **empty** list, while `low` answered in 5.6 seconds with three
 * requests. The same prompt on another sample of the default took 29 seconds and
 * returned four. More reasoning was buying variance, not quality.
 *
 * **This is not the trap CLAUDE.md documents.** What empties `gpt-oss:20b`'s
 * content is `think: false`; `"low"` leaves reasoning on and short, and the
 * daemon still separates it into `message.thinking`, which the gateway ignores.
 *
 * The default is config's to name, and an agent overrides `reasoningEffort()`
 * when its job is composition rather than reading — prose is where the extra
 * deliberation earns its seconds. Returning `null` sends no `think` key at all,
 * leaving the model's own default in place.
 */
trait ConfiguresOllamaRuntime
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

        // whereNotNull and not array_filter: `0` and `''` are not values this
        // sends, but null genuinely means "do not send the key".
        return Arr::whereNotNull([
            'num_ctx' => (int) config('ai.context_window'),
            'keep_alive' => config('ai.runtime.keep_alive'),
            'think' => $this->reasoningEffort(),
        ]);
    }

    /**
     * How hard the model should think before answering.
     *
     * `low`, `medium`, `high` — or null to send nothing and leave the model's
     * own default. Never `false`: see the class docblock.
     */
    protected function reasoningEffort(): ?string
    {
        $effort = config('ai.runtime.reasoning_effort');

        return is_string($effort) && $effort !== '' ? $effort : null;
    }
}
