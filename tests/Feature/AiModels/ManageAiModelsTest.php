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
            ->post('/modelos-de-ia', ['provider' => 'gemini', 'name' => 'gemini-3.7-flash', 'api_key' => 'AIzaChave'])
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
    public function a_gemini_model_needs_a_key_and_an_ollama_one_does_not(): void
    {
        $staff = User::factory()->platformAdmin()->create();

        $this->actingAs($staff)
            ->post('/modelos-de-ia', ['provider' => 'gemini', 'name' => 'gemini-3.7-flash'])
            ->assertSessionHasErrors('api_key');

        $this->actingAs($staff)
            ->post('/modelos-de-ia', ['provider' => 'gemini', 'name' => 'gemini-3.7-flash', 'api_key' => 'AIza chave'])
            ->assertSessionHasErrors('api_key');

        $this->actingAs($staff)
            ->post('/modelos-de-ia', ['provider' => 'ollama', 'name' => 'gpt-oss:20b'])
            ->assertSessionHasNoErrors();

        $this->assertNull(AiModel::query()->sole()->api_key);
    }

    #[Test]
    public function the_key_is_encrypted_at_rest_and_never_reaches_the_screen(): void
    {
        $staff = User::factory()->platformAdmin()->create();

        $this->actingAs($staff)
            ->post('/modelos-de-ia', ['provider' => 'gemini', 'name' => 'gemini-3.7-flash', 'api_key' => 'AIzaSegredo9kZA'])
            ->assertSessionHasNoErrors();

        $stored = DB::table('ai_models')->value('api_key');

        $this->assertIsString($stored);
        $this->assertStringNotContainsString('AIzaSegredo9kZA', $stored);
        $this->assertSame('AIzaSegredo9kZA', AiModel::query()->sole()->api_key);
        $this->assertArrayNotHasKey('api_key', AiModel::query()->sole()->toArray());

        $this->actingAs($staff)
            ->get('/modelos-de-ia')
            ->assertDontSee('AIzaSegredo9kZA')
            ->assertInertia(fn ($page) => $page
                ->where('models.0.api_key_hint', '••••9kZA')
                ->missing('models.0.api_key')
                ->where('keyed_providers', ['gemini']));
    }

    #[Test]
    public function a_correction_keeps_the_key_unless_one_is_typed_or_the_provider_changes(): void
    {
        $staff = User::factory()->platformAdmin()->create();
        $model = AiModel::factory()->create(['name' => 'gemini-3.6-flash', 'api_key' => 'chave-antiga']);
        $url = "/modelos-de-ia/{$model->id}";

        $this->actingAs($staff)->put($url, ['provider' => 'gemini', 'name' => 'gemini-3.7-flash', 'api_key' => ''])->assertSessionHasNoErrors();
        $this->assertSame('chave-antiga', $model->refresh()->api_key);

        $this->actingAs($staff)->put($url, ['provider' => 'gemini', 'name' => 'gemini-3.7-flash', 'api_key' => 'chave-nova'])->assertSessionHasNoErrors();
        $this->assertSame('chave-nova', $model->refresh()->api_key);

        // A key belongs to its provider: switching drops it…
        $this->actingAs($staff)->put($url, ['provider' => 'ollama', 'name' => 'gpt-oss:20b'])->assertSessionHasNoErrors();
        $this->assertNull($model->refresh()->api_key);

        // …and switching back has to bring one.
        $this->actingAs($staff)->put($url, ['provider' => 'gemini', 'name' => 'gemini-3.7-flash'])->assertSessionHasErrors('api_key');
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
