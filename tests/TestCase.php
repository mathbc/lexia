<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Accounts\Models\Account;
use App\Domain\AiModels\Enums\AiProvider;
use App\Domain\AiModels\Models\AiModel;
use App\Domain\AiModels\Models\EmbeddingModel;
use App\Domain\AiModels\Support\DefaultModels;
use App\Domain\Shared\Tenancy\TenantContext;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every test starts on fixed default models, held in memory.
     *
     * The agents and the embedders ask DefaultModels on every call, faked or
     * not, and a test that fakes an agent should not need a row in
     * `ai_models` to do it. The embedding default is the local nomic because
     * the retrieval tests assert its task prefixes; a test that needs another
     * pair calls usingDefaultModels(), and one that exercises the database
     * resolution calls readDefaultModelsFromDatabase().
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->usingDefaultModels(
            new AiModel(['provider' => AiProvider::Gemini, 'name' => 'gemini-3.7-flash', 'api_key' => self::geminiKey()]),
            new EmbeddingModel(['provider' => AiProvider::Ollama, 'name' => 'nomic-embed-text']),
        );
    }

    /**
     * The key an in-memory Gemini model carries.
     *
     * A faked agent never sends it, and any non-empty string gets it past
     * ProviderInstance. The agents suite does send it, and the testing database
     * has no catalogue to read one from, so it comes from the shell:
     * `GEMINI_API_KEY=… composer test:agents`. getenv() and not env() — this is
     * the process environment on purpose, not a configuration value, and the
     * `.env` no longer holds a key.
     */
    protected static function geminiKey(): string
    {
        $key = getenv('GEMINI_API_KEY');

        return is_string($key) && $key !== '' ? $key : 'test-key';
    }

    protected function usingDefaultModels(?AiModel $text = null, ?EmbeddingModel $embedding = null): void
    {
        $this->app->singleton(DefaultModels::class, static fn (): DefaultModels => DefaultModels::using($text, $embedding));
    }

    protected function readDefaultModelsFromDatabase(): void
    {
        $this->app->singleton(DefaultModels::class, static fn (): DefaultModels => new DefaultModels);
    }

    /**
     * Sign in and pin the tenant, the way BindTenantContext does per request.
     *
     * Tests that exercise HTTP go through the middleware for real; this helper
     * is for the ones that call Actions or query models directly.
     */
    protected function actingAsUser(User $user): User
    {
        $this->actingAs($user);

        app(TenantContext::class)->set($user->account_id);

        return $user;
    }

    /**
     * @return array{0: Account, 1: User}
     */
    protected function accountWithOwner(): array
    {
        $account = Account::factory()->create();
        $owner = User::factory()->forAccount($account)->accountAdmin()->create();

        return [$account, $owner];
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }
}
