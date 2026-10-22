<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Shared\Support\GroundingRedirect;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The page behind a Gemini grounding citation, followed one hop.
 */
final class GroundingRedirectTest extends TestCase
{
    private const string REDIRECT = 'https://vertexaisearch.cloud.google.com/grounding-api-redirect/AUZIYQ';

    private const string RECORD = 'https://www.lexml.gov.br/urn/urn:lex:br:rede.virtual.bibliotecas:artigo.revista:1995;1000506067';

    #[Test]
    public function a_vertex_redirect_resolves_to_its_location_without_fetching_it(): void
    {
        Http::fake([
            self::REDIRECT => Http::response('', 302, ['Location' => self::RECORD]),
            '*' => Http::response('não deveria ser buscado', 500),
        ]);

        $this->assertSame(self::RECORD, GroundingRedirect::resolve(self::REDIRECT));

        Http::assertSentCount(1);
    }

    #[Test]
    public function an_address_that_is_not_a_redirect_comes_back_unchanged(): void
    {
        Http::fake();

        $this->assertSame(self::RECORD, GroundingRedirect::resolve(self::RECORD));

        Http::assertNothingSent();
    }

    #[Test]
    public function a_redirect_that_does_not_redirect_is_dropped(): void
    {
        Http::fake([self::REDIRECT => Http::response('expirado', 404)]);

        $this->assertNull(GroundingRedirect::resolve(self::REDIRECT));
    }
}
