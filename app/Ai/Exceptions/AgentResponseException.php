<?php

declare(strict_types=1);

namespace App\Ai\Exceptions;

use Laravel\Ai\Responses\AgentResponse;
use RuntimeException;

/**
 * An agent answered, and the Action that called it refused the answer.
 *
 * The other failures name their agent for free: a timeout or a 503 is thrown
 * from inside `prompt()`, so the agent is a frame of the stack trace, and
 * `AgentFailureData` reads it there. This one is thrown *after* `prompt()`
 * returned — the empty sheet, the response with no structure —, and by then the
 * agent is gone from the stack. So it carries the agent by name, and what the
 * model said back, which is the half of the story the execution log is for.
 */
final class AgentResponseException extends RuntimeException
{
    /**
     * @param  class-string  $agent
     */
    public function __construct(
        public readonly string $agent,
        string $message,
        public readonly ?string $modelMessage = null,
    ) {
        parent::__construct($message);
    }

    /**
     * The answer that came back blank, with the only thing a blank answer has
     * to say: the provider's reason for stopping — `RECITATION`, `SAFETY`,
     * `MAX_TOKENS` —, when the raw response carries one.
     *
     * @param  class-string  $agent
     */
    public static function blank(string $agent, string $message, AgentResponse $response): self
    {
        $reason = $response->raw?->json('candidates.0.finishReason');

        return new self($agent, $message, is_string($reason) ? "finishReason: {$reason}" : null);
    }
}
