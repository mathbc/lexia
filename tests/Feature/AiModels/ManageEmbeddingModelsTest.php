<?php

declare(strict_types=1);

namespace Tests\Feature\AiModels;

use App\Domain\AiModels\Actions\EmbedCatalogs;
use App\Domain\AiModels\Enums\AiProvider;
use App\Domain\AiModels\Models\EmbeddingModel;
use App\Domain\LegalThemes\Models\LegalTheme;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The embedding models catalogue. Same screen as the text one; what differs is
 * that changing the default makes every stored vector stale, so it queues the
 * re-embedding.
 */
final class ManageEmbeddingModelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    #[Test]
    public function staff_see_the_catalogue_and_nobody_else_does(): void
    {
        $staff = User::factory()->platformAdmin()->create();
        [, $owner] = $this->accountWithOwner();
        EmbeddingModel::factory()->asDefault()->create();

        $this->actingAs($staff)
            ->get('/modelos-de-embedding')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('embedding-models/index')
                ->has('models', 1)
                ->where('models.0.default', true));

        $this->actingAs($owner)->get('/modelos-de-embedding')->assertForbidden();
    }

    #[Test]
    public function the_first_model_becomes_the_default_and_queues_the_catalogues(): void
    {
        $staff = User::factory()->platformAdmin()->create();

        $this->actingAs($staff)
            ->post('/modelos-de-embedding', ['provider' => 'gemini', 'name' => 'gemini-embedding-001'])
            ->assertRedirect('/modelos-de-embedding');

        $this->assertSame('gemini-embedding-001', EmbeddingModel::platformDefault()?->name);
        EmbedCatalogs::assertPushed(1);
    }

    #[Test]
    public function registering_another_model_queues_nothing(): void
    {
        $staff = User::factory()->platformAdmin()->create();
        EmbeddingModel::factory()->asDefault()->create();

        $this->actingAs($staff)
            ->post('/modelos-de-embedding', ['provider' => 'ollama', 'name' => 'nomic-embed-text'])
            ->assertRedirect('/modelos-de-embedding');

        EmbedCatalogs::assertNotPushed();
    }

    #[Test]
    public function promoting_a_model_queues_the_catalogues(): void
    {
        $staff = User::factory()->platformAdmin()->create();
        $old = EmbeddingModel::factory()->asDefault()->create();
        $new = EmbeddingModel::factory()->ollama()->create();

        $this->actingAs($staff)
            ->patch("/modelos-de-embedding/{$new->id}/padrao")
            ->assertRedirect('/modelos-de-embedding');

        $this->assertTrue($new->fresh()?->default);
        $this->assertFalse($old->fresh()?->default);
        EmbedCatalogs::assertPushed(1);
    }

    #[Test]
    public function renaming_the_default_queues_the_catalogues_and_renaming_another_does_not(): void
    {
        $staff = User::factory()->platformAdmin()->create();
        $default = EmbeddingModel::factory()->asDefault()->create(['name' => 'gemini-embedding-001']);
        $other = EmbeddingModel::factory()->ollama()->create();

        $this->actingAs($staff)
            ->put("/modelos-de-embedding/{$other->id}", ['provider' => 'ollama', 'name' => 'mxbai-embed-large'])
            ->assertRedirect('/modelos-de-embedding');

        EmbedCatalogs::assertNotPushed();

        // Saving the default unchanged is not a change of model either.
        $this->actingAs($staff)
            ->put("/modelos-de-embedding/{$default->id}", ['provider' => 'gemini', 'name' => 'gemini-embedding-001'])
            ->assertRedirect('/modelos-de-embedding');

        EmbedCatalogs::assertNotPushed();

        $this->actingAs($staff)
            ->put("/modelos-de-embedding/{$default->id}", ['provider' => 'gemini', 'name' => 'gemini-embedding-002'])
            ->assertRedirect('/modelos-de-embedding');

        EmbedCatalogs::assertPushed(1);
    }

    #[Test]
    public function the_default_is_never_deleted(): void
    {
        $staff = User::factory()->platformAdmin()->create();
        $default = EmbeddingModel::factory()->asDefault()->create();

        $this->actingAs($staff)->delete("/modelos-de-embedding/{$default->id}")->assertForbidden();

        $this->assertModelExists($default);
    }

    /**
     * The job itself: whatever the previous model made is redone with the
     * default, and a second run has nothing left to do.
     */
    #[Test]
    public function the_job_brings_both_catalogues_up_to_the_default(): void
    {
        Embeddings::fake();
        $theme = LegalTheme::factory()->embedded(array_fill(0, 768, 0.1))->create();

        $this->usingDefaultModels(embedding: new EmbeddingModel([
            'provider' => AiProvider::Gemini,
            'name' => 'gemini-embedding-001',
        ]));

        $first = EmbedCatalogs::run();

        $this->assertSame(ProceduralClass::query()->count() + 1, $first);
        $this->assertSame('gemini:gemini-embedding-001', $theme->fresh()?->embedding_model);
        $this->assertSame(0, ProceduralClass::query()->where('embedding_model', '!=', 'gemini:gemini-embedding-001')->count());
        $this->assertSame(0, EmbedCatalogs::run());
    }
}
