<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lifts PHP's execution limit off the routes that wait on an agent.
 *
 * `#[Timeout(360)]` is the HTTP client's patience, not the interpreter's. The
 * two are unrelated, and the default `max_execution_time = 30` of a stock
 * `php.ini` kills the request long before any agent has answered:
 *
 *     Maximum execution time of 30+2 seconds exceeded (terminated)
 *     in vendor/guzzlehttp/guzzle/src/Handler/CurlHandler.php
 *
 * The `+2` is the tell, and it names the mechanism. PHP's ordinary timeout
 * cannot interrupt a blocking `curl_exec()`, so the *hard* timeout does it two
 * seconds later — with `(terminated)` instead of an exception. Nothing catches
 * that, nothing is written, and the lawyer sees a fatal error where a pleading
 * should be. Raising the agent's `Timeout` would not have moved it by a second.
 *
 * ## Why a middleware and not a line in each Action
 *
 * The limit is a property of the request, not of the use case: `handle()` knows
 * nothing about HTTP, and a queue worker or a console command running the same
 * Action has no limit to lift. So it lives where the other request-shaped
 * concerns live, and the routes that need it say so out loud — four of them,
 * every one that waits on an inference.
 *
 * ## It only ever raises
 *
 * A limit of zero is unlimited, and that is what the CLI and the built-in
 * server hand us. Setting a number there would *impose* a ceiling where none
 * existed, so a zero is left alone — this middleware removes a wall, it never
 * builds one.
 *
 * Note the boundary this does not cross: under PHP-FPM behind a web server,
 * `fastcgi_read_timeout` (nginx) and `request_terminate_timeout` (the pool)
 * cut the same request from outside, and no amount of `set_time_limit()`
 * reaches them. The real answer for production is the queue that
 * `ResearchLegalCaseForensicReview` and `ClassifyLegalCase` both document as
 * their standing debt; this is what makes the synchronous path work meanwhile.
 */
final class AllowLongInference
{
    public function handle(Request $request, Closure $next): Response
    {
        $limit = (int) config('ai.request_time_limit');
        $current = (int) ini_get('max_execution_time');

        if ($current !== 0 && $current < $limit && function_exists('set_time_limit')) {
            // Reinicia o cronômetro, e não só o teto: a contagem recomeça
            // daqui, que é onde a espera pela inferência de fato começa.
            set_time_limit($limit);
        }

        return $next($request);
    }
}
