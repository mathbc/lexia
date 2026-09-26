<?php

declare(strict_types=1);

namespace Tests\Feature\LegalThemes;

use App\Domain\LegalThemes\Models\LegalTheme;
use App\Domain\LegalThemes\Support\StjLegalThemesFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * O comando `lexia:import-legal-themes`: download, importação e vetorização,
 * e as duas fronteiras entre eles.
 */
final class ImportLegalThemesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    #[Test]
    public function it_downloads_imports_and_embeds(): void
    {
        $this->fakePortal();
        Embeddings::fake();

        $this->artisan('lexia:import-legal-themes')
            ->expectsOutputToContain('7 precedentes (7 novos), 5 vínculos de repercussão geral')
            ->expectsOutputToContain('Embeddings gerados: 7.')
            ->assertSuccessful();

        $this->assertSame(7, LegalTheme::query()->whereNotNull('embedding')->count());
        Http::assertSent(static fn ($request): bool => $request->url() === StjLegalThemesFeed::URL);
    }

    #[Test]
    public function a_second_run_changes_nothing(): void
    {
        $this->fakePortal();
        Embeddings::fake();

        $this->artisan('lexia:import-legal-themes')->assertSuccessful();

        $this->artisan('lexia:import-legal-themes')
            ->expectsOutputToContain('7 precedentes (0 novos)')
            ->expectsOutputToContain('Nada a fazer')
            ->assertSuccessful();
    }

    #[Test]
    public function a_local_file_skips_the_network_and_is_never_deleted(): void
    {
        Embeddings::fake();

        $this->artisan('lexia:import-legal-themes', [
            '--file' => ImportLegalThemesTest::FIXTURE,
            '--skip-embeddings' => true,
        ])->assertSuccessful();

        $this->assertSame(7, LegalTheme::query()->count());
        $this->assertFileExists(ImportLegalThemesTest::FIXTURE);
        Http::assertNothingSent();
        Embeddings::assertNothingGenerated();
    }

    #[Test]
    public function a_failed_download_writes_nothing(): void
    {
        Http::fake([StjLegalThemesFeed::URL => Http::response('Service Unavailable', 503)]);

        $this->artisan('lexia:import-legal-themes')
            ->expectsOutputToContain('Falha ao baixar o arquivo de temas')
            ->assertFailed();

        $this->assertSame(0, LegalTheme::query()->count());
    }

    #[Test]
    public function a_failed_embedding_keeps_the_import(): void
    {
        $this->fakePortal();
        Embeddings::fake(static fn () => throw new RuntimeException('Ollama fora do ar'));

        $this->artisan('lexia:import-legal-themes')
            ->expectsOutputToContain('Falha ao gerar embeddings: Ollama fora do ar')
            ->expectsOutputToContain('Rode de novo')
            ->assertFailed();

        $this->assertSame(7, LegalTheme::query()->count());
        $this->assertSame(0, LegalTheme::query()->whereNotNull('embedding')->count());
    }

    private function fakePortal(): void
    {
        // Uma resposta nova por requisição: o corpo de uma resposta fixa é um
        // stream, e o sink da primeira o deixaria no fim para a segunda.
        Http::fake([
            StjLegalThemesFeed::URL => static fn () => Http::response((string) file_get_contents(ImportLegalThemesTest::FIXTURE), 200, [
                'Content-Type' => 'text/csv',
            ]),
        ]);
    }
}
