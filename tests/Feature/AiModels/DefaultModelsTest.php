<?php

declare(strict_types=1);

namespace Tests\Feature\AiModels;

use App\Ai\Agents\FactsScreeningAgent;
use App\Ai\Agents\LegalThesisResearchAgent;
use App\Domain\AiModels\Enums\AiProvider;
use App\Domain\AiModels\Exceptions\MissingDefaultModelException;
use App\Domain\AiModels\Models\AiModel;
use App\Domain\AiModels\Models\EmbeddingModel;
use App\Domain\AiModels\Support\DefaultModels;
use App\Domain\Shared\Support\DocumentEmbedder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Where the agents and the embedders get their model and its key: the default
 * rows, and never the environment.
 */
final class DefaultModelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->readDefaultModelsFromDatabase();
    }

    #[Test]
    public function a_database_without_defaults_fails_loudly(): void
    {
        $models = app(DefaultModels::class);

        try {
            $models->text();
            $this->fail('A text model was resolved with no default registered.');
        } catch (MissingDefaultModelException $e) {
            $this->assertStringContainsString('lexia:install', $e->getMessage());
        }

        $this->expectException(MissingDefaultModelException::class);

        $models->embedding();
    }

    #[Test]
    public function the_defaults_are_the_flagged_rows(): void
    {
        AiModel::factory()->create();
        $text = AiModel::factory()->ollama()->asDefault()->create();
        $embedding = EmbeddingModel::factory()->asDefault()->create();

        $models = app(DefaultModels::class);

        $this->assertTrue($models->text()->is($text));
        $this->assertTrue($models->embedding()->is($embedding));
    }

    #[Test]
    public function an_agent_prompts_the_default_provider_and_model(): void
    {
        AiModel::factory()->ollama('gpt-oss:20b')->asDefault()->create();

        FactsScreeningAgent::fake([[
            'instructs_the_system' => false,
            'intelligible' => true,
            'legal_matter' => true,
            'describes_events' => true,
            'states_claim' => true,
            'has_timeline' => true,
            'reason' => 'Narra a dispensa.',
        ]]);

        (new FactsScreeningAgent('guia'))->prompt('Fui demitido em março.');

        FactsScreeningAgent::assertPrompted(static fn (AgentPrompt $prompt): bool => $prompt->model === 'gpt-oss:20b'
            && $prompt->provider->name() === 'ollama');
    }

    /**
     * Both defaults on Gemini, billed to different keys, in the same request:
     * each request goes out with its own row's key.
     */
    #[Test]
    public function the_text_and_the_embedding_defaults_each_send_their_own_key(): void
    {
        AiModel::factory()->asDefault()->create(['name' => 'gemini-3.7-flash', 'api_key' => 'chave-do-texto']);
        EmbeddingModel::factory()->asDefault()->create(['name' => 'gemini-embedding-001', 'api_key' => 'chave-dos-vetores']);

        FactsScreeningAgent::fake([[
            'instructs_the_system' => false,
            'intelligible' => true,
            'legal_matter' => true,
            'describes_events' => true,
            'states_claim' => true,
            'has_timeline' => true,
            'reason' => 'Narra a dispensa.',
        ]]);
        Embeddings::fake();

        (new FactsScreeningAgent('guia'))->prompt('Fui demitido em março.');
        DocumentEmbedder::embedQueries(['dispensa sem justa causa'], 30);

        FactsScreeningAgent::assertPrompted(static fn (AgentPrompt $prompt): bool => $prompt->provider->driver() === 'gemini'
            && $prompt->provider->providerCredentials()['key'] === 'chave-do-texto');

        Embeddings::assertGenerated(static fn (EmbeddingsPrompt $prompt): bool => $prompt->provider->driver() === 'gemini'
            && $prompt->provider->providerCredentials()['key'] === 'chave-dos-vetores');
    }

    /**
     * A key replaced in the panel reaches the next request even in a process
     * that already prompted with the old one — a queue worker, say.
     */
    #[Test]
    public function a_replaced_key_is_never_served_from_a_stale_instance(): void
    {
        $model = AiModel::factory()->asDefault()->create(['api_key' => 'chave-antiga']);
        FactsScreeningAgent::fake(static fn (): array => [
            'instructs_the_system' => false,
            'intelligible' => true,
            'legal_matter' => true,
            'describes_events' => true,
            'states_claim' => true,
            'has_timeline' => true,
            'reason' => 'Narra a dispensa.',
        ]);

        (new FactsScreeningAgent('guia'))->prompt('Fui demitido em março.');

        $model->update(['api_key' => 'chave-nova']);
        $this->readDefaultModelsFromDatabase();

        (new FactsScreeningAgent('guia'))->prompt('Fui demitido em abril.');

        FactsScreeningAgent::assertPrompted(static fn (AgentPrompt $prompt): bool => $prompt->contains('abril')
            && $prompt->provider->providerCredentials()['key'] === 'chave-nova');
    }

    #[Test]
    public function a_default_without_the_key_its_provider_requires_fails_loudly(): void
    {
        AiModel::factory()->withoutApiKey()->asDefault()->create();
        FactsScreeningAgent::fake()->preventStrayPrompts();

        $this->expectException(MissingDefaultModelException::class);
        $this->expectExceptionMessage('não tem chave de API');

        (new FactsScreeningAgent('guia'))->prompt('Fui demitido em março.');
    }

    /**
     * Pointing a researcher at the daemon would not research worse, it would
     * not research at all — so it refuses before the prompt is built.
     */
    #[Test]
    public function a_research_agent_refuses_a_default_that_cannot_search(): void
    {
        AiModel::factory()->ollama()->asDefault()->create();
        LegalThesisResearchAgent::fake()->preventStrayPrompts();

        $this->expectException(MissingDefaultModelException::class);
        $this->expectExceptionMessage('busca na web');

        (new LegalThesisResearchAgent('dossiê', [], [], []))->prompt('Pesquise.');
    }

    #[Test]
    public function a_research_agent_runs_on_a_gemini_default(): void
    {
        AiModel::factory()->asDefault()->create(['provider' => AiProvider::Gemini, 'name' => 'gemini-3.7-flash']);
        LegalThesisResearchAgent::fake(['Ficha.']);

        (new LegalThesisResearchAgent('dossiê', [], [], []))->prompt('Pesquise.');

        LegalThesisResearchAgent::assertPrompted(static fn (AgentPrompt $prompt): bool => $prompt->model === 'gemini-3.7-flash');
    }
}
