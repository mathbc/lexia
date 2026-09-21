<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Http\Middleware\AllowLongInference;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The execution ceiling that has to be lifted off the routes that wait.
 *
 * A regression here is invisible in development until it is not: the request
 * dies at 32 seconds inside Guzzle's `curl_exec()` with a fatal error rather
 * than an exception — `Maximum execution time of 30+2 seconds exceeded
 * (terminated)` — so nothing is written, nothing is reported, and the screen
 * shows a stack trace where a pleading should be. Nothing else in the suite
 * would notice, because the suite never waits on a real inference.
 *
 * So what is pinned is the wiring and the one rule the middleware has: it
 * raises a limit and never lowers one.
 */
final class LongInferenceRoutesTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function inferenceRoutes(): array
    {
        return [
            'o enquadramento' => ['legal-cases.classify'],
            'a pesquisa de teses' => ['legal-cases.forensic-review.research'],
            'a conclusão da peça' => ['legal-cases.finalize'],
            'a minuta gerada de novo' => ['legal-cases.pleading.generate'],
        ];
    }

    #[Test]
    #[DataProvider('inferenceRoutes')]
    public function every_route_that_waits_on_an_agent_lifts_the_time_limit(string $name): void
    {
        $route = Route::getRoutes()->getByName($name);

        $this->assertNotNull($route, "A rota {$name} não existe.");
        $this->assertContains('inference', $route->gatherMiddleware());
    }

    #[Test]
    public function a_route_that_does_not_infer_is_left_alone(): void
    {
        // O contraponto: se o middleware acabasse no grupo `web`, este teste é
        // o que diria. Uma etapa que só grava não deve esperar quinze minutos
        // por um banco que não responde.
        $route = Route::getRoutes()->getByName('legal-cases.facts');

        $this->assertNotNull($route);
        $this->assertNotContains('inference', $route->gatherMiddleware());
    }

    #[Test]
    public function the_limit_is_raised_and_the_request_goes_on(): void
    {
        config(['ai.request_time_limit' => 900]);

        $before = (int) ini_get('max_execution_time');

        try {
            ini_set('max_execution_time', '30');

            $response = (new AllowLongInference)->handle(
                Request::create('/pecas/classificar', 'POST'),
                fn (): Response => new Response('ok'),
            );

            $this->assertSame(900, (int) ini_get('max_execution_time'));
            $this->assertSame('ok', $response->getContent());
        } finally {
            ini_set('max_execution_time', (string) $before);
        }
    }

    #[Test]
    public function an_unlimited_process_is_not_given_a_ceiling(): void
    {
        // Zero é ilimitado, e é o que a CLI e o `artisan serve` entregam.
        // Escrever 900 ali seria construir uma parede onde não havia nenhuma —
        // este middleware só derruba.
        config(['ai.request_time_limit' => 900]);

        $before = (int) ini_get('max_execution_time');

        try {
            ini_set('max_execution_time', '0');

            (new AllowLongInference)->handle(
                Request::create('/pecas/classificar', 'POST'),
                fn (): Response => new Response('ok'),
            );

            $this->assertSame(0, (int) ini_get('max_execution_time'));
        } finally {
            ini_set('max_execution_time', (string) $before);
        }
    }
}
