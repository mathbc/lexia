<?php

declare(strict_types=1);

namespace Tests\Feature\LegalThemes;

use App\Domain\LegalThemes\Actions\EmbedLegalThemes;
use App\Domain\LegalThemes\Actions\ImportLegalThemes;
use App\Domain\LegalThemes\Models\LegalTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A vetorização dos temas do STJ.
 *
 * Fora do grupo `agents` pelo motivo de ProceduralClassRankingTest: o fake do
 * SDK fabrica os vetores, e o que se verifica é quem é embutido e com que
 * texto, não a qualidade do modelo.
 */
final class EmbedLegalThemesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.default_for_embeddings' => 'ollama']);
        ImportLegalThemes::run(ImportLegalThemesTest::FIXTURE);
        Embeddings::fake();
    }

    #[Test]
    public function it_embeds_every_theme_and_records_the_hash(): void
    {
        $this->assertSame(7, EmbedLegalThemes::run(LegalTheme::all()));

        foreach (LegalTheme::all() as $theme) {
            $this->assertCount(768, (array) $theme->embedding);
            $this->assertSame(64, strlen((string) $theme->embedding_hash));
            $this->assertNotNull($theme->embedded_at);
        }
    }

    #[Test]
    public function a_second_pass_embeds_nothing(): void
    {
        EmbedLegalThemes::run(LegalTheme::all());

        $this->assertSame(0, EmbedLegalThemes::run(LegalTheme::all()));
    }

    #[Test]
    public function only_a_change_in_the_text_is_embedded_again(): void
    {
        EmbedLegalThemes::run(LegalTheme::all());

        // A situação é filtro, não conteúdo: mudar de "Afetado" para
        // "Trânsito em Julgado" não pode custar uma reembutida.
        $this->theme(1301)->forceFill(['status' => 'Revisado'])->save();
        $this->assertSame(0, EmbedLegalThemes::run(LegalTheme::all()));

        $this->theme(1301)->forceFill(['settled_thesis' => 'Tese revisada.'])->save();
        $this->assertSame(1, EmbedLegalThemes::run(LegalTheme::all()));

        Embeddings::assertGenerated(static fn (EmbeddingsPrompt $prompt): bool => count($prompt->inputs) === 1
            && str_contains($prompt->inputs[0], 'Tese firmada: Tese revisada.'));
    }

    #[Test]
    public function the_document_is_the_subject_matter_behind_the_task_prefix(): void
    {
        $theme = $this->theme(1301);
        $document = (new EmbedLegalThemes)->documentFor($theme);

        $this->assertStringStartsWith("search_document: Tema Repetitivo 952 do STJ.\nAssuntos: ", $document);
        $this->assertStringContainsString("Questão submetida a julgamento: {$theme->question}", $document);
        $this->assertStringContainsString("Tese firmada: {$theme->settled_thesis}", $document);
        $this->assertStringNotContainsString($theme->status, $document);
        $this->assertStringNotContainsString((string) $theme->nugepnac_notes, $document);
    }

    #[Test]
    public function a_provider_that_does_not_read_the_prefix_gets_none(): void
    {
        config(['ai.default_for_embeddings' => 'openai']);

        $this->assertStringStartsWith(
            'Tema Repetitivo 952 do STJ.',
            (new EmbedLegalThemes)->documentFor($this->theme(1301)),
        );
    }

    private function theme(int $sequentialNumber): LegalTheme
    {
        return LegalTheme::query()->where('sequential_number', $sequentialNumber)->sole();
    }
}
