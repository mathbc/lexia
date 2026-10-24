<?php

declare(strict_types=1);

namespace App\Domain\AgentsExecutionLogs\Data;

use App\Ai\Exceptions\AgentResponseException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Throwable;

/**
 * An agent's failure, read out of the exception chain into the four things the
 * execution log keeps.
 *
 * The chain is the point. The SDK never lets the HTTP error out as it is: a 503
 * becomes `ProviderOverloadedException`, a 429 `RateLimitedException`, a cURL
 * timeout `ProviderConnectionException` (`HandlesFailoverErrors`), and the
 * response that says *why* is the `previous` of the one that reaches us. Read
 * only the outer exception and every row says "AI provider [gemini] is
 * overloaded", which is the one sentence nobody needed.
 *
 * - **The status** is the response's, from the first `RequestException` in the
 *   chain. A timeout has none — nobody answered —, and stays null.
 * - **The message** is what came back from the other side: the provider's
 *   `error.message`, its raw body when it wrote no such key, or what the model
 *   answered when an Action refused it (`AgentResponseException`).
 * - **The agent** is named by `AgentResponseException`, or read from the stack:
 *   a failure thrown inside `prompt()` has the agent's class among its frames —
 *   a trait method reports the class that uses it, so `Promptable::prompt()`
 *   shows up as `LegalThesisResearchAgent`. Failing both, the caller's fallback,
 *   which is the Action that held the stage.
 * - **The error** is the outer exception and, when the chain is longer, its
 *   root cause.
 */
final readonly class AgentFailureData
{
    private const string AGENTS_NAMESPACE = 'App\\Ai\\Agents\\';

    /** The provider's body can be a whole HTML error page. */
    private const int TEXT_LIMIT = 2000;

    /** A chain deeper than this is a loop, not a cause. */
    private const int CHAIN_LIMIT = 10;

    public function __construct(
        public string $agent,
        public ?int $httpStatus,
        public ?string $message,
        public string $error,
    ) {}

    public static function from(Throwable $failure, string $fallbackAgent): self
    {
        $chain = self::chain($failure);

        return new self(
            agent: self::agent($chain) ?? class_basename($fallbackAgent),
            httpStatus: self::response($chain)?->status(),
            message: self::message($chain),
            error: self::error($chain),
        );
    }

    /**
     * @return non-empty-list<Throwable>
     */
    private static function chain(Throwable $failure): array
    {
        $chain = [$failure];

        while (count($chain) < self::CHAIN_LIMIT && ($previous = end($chain)->getPrevious()) !== null) {
            $chain[] = $previous;
        }

        return $chain;
    }

    /**
     * @param  list<Throwable>  $chain
     */
    private static function agent(array $chain): ?string
    {
        $refusal = self::refusal($chain);

        if ($refusal !== null) {
            return class_basename($refusal->agent);
        }

        foreach ($chain as $failure) {
            foreach ($failure->getTrace() as $frame) {
                $class = $frame['class'] ?? '';

                if (str_starts_with($class, self::AGENTS_NAMESPACE)) {
                    return class_basename($class);
                }
            }
        }

        return null;
    }

    /**
     * @param  list<Throwable>  $chain
     */
    private static function message(array $chain): ?string
    {
        $refusal = self::refusal($chain);

        if ($refusal !== null) {
            return self::limited($refusal->modelMessage);
        }

        $response = self::response($chain);

        if ($response === null) {
            return null;
        }

        $message = $response->json('error.message');

        return self::limited(is_string($message) && trim($message) !== '' ? $message : $response->body());
    }

    /**
     * @param  non-empty-list<Throwable>  $chain
     */
    private static function error(array $chain): string
    {
        $outer = $chain[0];
        $root = $chain[array_key_last($chain)];

        $error = $outer::class.': '.$outer->getMessage();

        if ($root !== $outer) {
            $error .= ' ← '.$root::class.': '.$root->getMessage();
        }

        return Str::limit($error, self::TEXT_LIMIT);
    }

    /**
     * @param  list<Throwable>  $chain
     */
    private static function refusal(array $chain): ?AgentResponseException
    {
        foreach ($chain as $failure) {
            if ($failure instanceof AgentResponseException) {
                return $failure;
            }
        }

        return null;
    }

    /**
     * @param  list<Throwable>  $chain
     */
    private static function response(array $chain): ?Response
    {
        foreach ($chain as $failure) {
            if ($failure instanceof RequestException) {
                return $failure->response;
            }
        }

        return null;
    }

    private static function limited(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : Str::limit($text, self::TEXT_LIMIT);
    }
}
