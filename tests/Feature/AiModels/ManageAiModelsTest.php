<?php

declare(strict_types=1);

namespace Tests\Feature\AiModels;

use App\Domain\AiModels\Enums\AiProvider;
use App\Domain\AiModels\Models\AiModel;
use App\Domain\Users\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The text models catalogue: only LexIA staff reach it, and exactly one row
 * is the default the agents run on.
 */
final class ManageAiModelsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function staff_see_the_catalogue_with_the_default_first(): void
    {
        $staff = User::factory()->platformAdmin()->create();
        AiModel::factory()->ollama()->create();
        $default = AiModel::factory()->asDefault()->create();

        $this->actingAs($staff)
            ->get('/modelos-de-ia')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ai-models/index')
                ->has('models', 2)
                ->where('models.0.id', $default->id)
                ->where('models.0.default', true)
                ->where('models.0.can.delete', false)
                ->where('models.0.can.make_default', false)
                ->where('models.1.can.delete', true)
                ->where('models.1.can.make_default', true)
                ->where('can.create', true)
                ->has('providers', count(AiProvider::cases())));
    }

    #[Test]
    public function nobody_inside_an_account_reaches_the_catalogue(): void
    {
        [, $owner] = $this->accountWithOwner();
        $model = AiModel::factory()->create();

        $this->actingAs($owner)->get('/modelos-de-ia')->assertForbidden();
        $this->actingAs($owner)->post('/modelos-de-ia', ['provider' => 'gemini', 'name' => 'x'])->assertForbidden();
        $this->actingAs($owner)->patch("/modelos-de-ia/{$model->id}/padrao")->assertForbidden();
        $this->actingAs($owner)->delete("/modelos-de-ia/{$model->id}")->assertForbidden();

        $this->assertFalse($model->fresh()?->default);
    }

    #[Test]
    public function the_first_model_becomes_the_default_and_the_next_does_not(): void
    {
        $staff = User::factory()->platformAdmin()->create();

        $this->actingAs($staff)
            ->post('/modelos-de-ia', ['provider' => 'gemini', 'name' => 'gemini-3.7-flash'])
            ->assertRedirect('/modelos-de-ia');

        $this->actingAs($staff)
            ->post('/modelos-de-ia', ['provider' => 'ollama', 'name' => 'gpt-oss:20b'])
            ->assertRedirect('/modelos-de-ia');

        $this->assertSame('gemini-3.7-flash', AiModel::platformDefault()?->name);
        $this->assertSame(1, AiModel::query()->where('default', true)->count());
    }

    #[Test]
    public function the_name_is_an_api_id_unique_per_provider(): void
    {
        $staff = User::factory()->platformAdmin()->create();
        AiModel::factory()->create(['provider' => AiProvider::Gemini, 'name' => 'gemini-3.7-flash']);

        $this->actingAs($staff)
            ->post('/modelos-de-ia', ['provider' => 'gemini', 'name' => 'gemini-3.7-flash'])
            ->assertSessionHasErrors('name');

        $this->actingAs($staff)
            ->post('/modelos-de-ia', ['provider' => 'gemini', 'name' => 'Gemini 3.7 Flash'])
            ->assertSessionHasErrors('name');

        $this->actingAs($staff)
            ->post('/modelos-de-ia', ['provider' => 'openai', 'name' => 'gpt-5'])
            ->assertSessionHasErrors('provider');

        // The same id under another provider is another model.
        $this->actingAs($staff)
            ->post('/modelos-de-ia', ['provider' => 'ollama', 'name' => 'gemini-3.7-flash'])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function promoting_a_model_hands_the_default_over(): void
    {
        $staff = User::factory()->platformAdmin()->create();
        $old = AiModel::factory()->asDefault()->create();
        $new = AiModel::factory()->ollama()->create();

        $this->actingAs($staff)
            ->patch("/modelos-de-ia/{$new->id}/padrao")
            ->assertRedirect('/modelos-de-ia');

        $this->assertTrue($new->fresh()?->default);
        $this->assertFalse($old->fresh()?->default);
    }

    #[Test]
    public function the_database_refuses_a_second_default(): void
    {
        AiModel::factory()->asDefault()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('ai_models')->insert([
            'id' => (string) str()->uuid(),
            'provider' => 'ollama',
            'name' => 'gpt-oss:20b',
            'default' => true,
        ]);
    }

    #[Test]
    public function a_model_is_corrected_in_place(): void
    {
        $staff = User::factory()->platformAdmin()->create();
        $model = AiModel::factory()->asDefault()->create(['name' => 'gemini-3.6-flash']);

        $this->actingAs($staff)
            ->put("/modelos-de-ia/{$model->id}", ['provider' => 'gemini', 'name' => 'gemini-3.7-flash'])
            ->assertRedirect('/modelos-de-ia');

        $model->refresh();

        $this->assertSame('gemini-3.7-flash', $model->name);
        $this->assertTrue($model->default);
    }

    #[Test]
    public function the_default_is_never_deleted(): void
    {
        $staff = User::factory()->platformAdmin()->create();
        $default = AiModel::factory()->asDefault()->create();
        $other = AiModel::factory()->ollama()->create();

        $this->actingAs($staff)->delete("/modelos-de-ia/{$default->id}")->assertForbidden();
        $this->actingAs($staff)->delete("/modelos-de-ia/{$other->id}")->assertRedirect('/modelos-de-ia');

        $this->assertModelExists($default);
        $this->assertModelMissing($other);
    }
}
