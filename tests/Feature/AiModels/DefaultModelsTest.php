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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Prompts\AgentPrompt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Where the agents and the embedders get their model: the default rows, and
 * never the environment.
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
