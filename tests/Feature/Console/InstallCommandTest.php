<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Models\Account;
use App\Domain\AiModels\Actions\EmbedCatalogs;
use App\Domain\AiModels\Models\AiModel;
use App\Domain\AiModels\Models\EmbeddingModel;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `lexia:install`: what a new database needs before anyone can sign in, done
 * in a way a deploy can repeat.
 */
final class InstallCommandTest extends TestCase
{
    use RefreshDatabase;

    private const array ADMIN = [
        '--name' => 'Suporte LexIA',
        '--email' => 'Suporte@LexIA.com.br',
        '--password' => 'uma-senha-longa',
        '--no-interaction' => true,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Promoting the embedding default queues the catalogues; the job is
        // EmbedCatalogs' own test.
        Queue::fake();
    }

    #[Test]
    public function it_creates_the_admin_and_registers_the_gemini_defaults(): void
    {
        $this->artisan('lexia:install', self::ADMIN)
            ->expectsOutputToContain('Conta da LexIA: já existe.')
            ->expectsOutputToContain('Administrador: suporte@lexia.com.br criado.')
            ->expectsOutputToContain('Modelo de IA: gemini-3.7-flash cadastrado (é o padrão).')
            ->expectsOutputToContain('Modelo de embedding: gemini-embedding-001 cadastrado (é o padrão).')
            ->assertSuccessful();

        $admin = User::acrossAllAccounts()->where('email', 'suporte@lexia.com.br')->sole();

        $this->assertSame(UserRole::PlatformAdmin, $admin->role);
        $this->assertSame(Account::PLATFORM_ID, $admin->account_id);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue($admin->enabled);
        $this->assertTrue(Hash::check('uma-senha-longa', $admin->password));
        $this->assertTrue($admin->canAccessPlatform());

        $this->assertSame('gemini-3.7-flash', AiModel::platformDefault()?->name);
        $this->assertSame('gemini-embedding-001', EmbeddingModel::platformDefault()?->name);
        EmbedCatalogs::assertPushed(1);
    }

    #[Test]
    public function running_it_again_changes_nothing(): void
    {
        $this->artisan('lexia:install', self::ADMIN)->assertSuccessful();

        $this->artisan('lexia:install', ['--no-interaction' => true])
            ->expectsOutputToContain('Administrador: já existe um admin do sistema — passo pulado.')
            ->expectsOutputToContain('Modelo de IA: gemini-3.7-flash já cadastrado (é o padrão).')
            ->assertSuccessful();

        $this->assertSame(1, User::acrossAllAccounts()->count());
        $this->assertSame(1, AiModel::query()->count());
        $this->assertSame(1, EmbeddingModel::query()->count());
        EmbedCatalogs::assertPushed(1);
    }

    #[Test]
    public function it_never_takes_back_a_default_chosen_in_the_panel(): void
    {
        AiModel::factory()->ollama('gpt-oss:20b')->asDefault()->create();
        EmbeddingModel::factory()->ollama()->asDefault()->create();

        $this->artisan('lexia:install', self::ADMIN)
            ->expectsOutputToContain('Modelo de IA: gemini-3.7-flash cadastrado (o padrão continua gpt-oss:20b).')
            ->assertSuccessful();

        $this->assertSame('gpt-oss:20b', AiModel::platformDefault()?->name);
        $this->assertSame('nomic-embed-text', EmbeddingModel::platformDefault()?->name);
        EmbedCatalogs::assertNotPushed();
    }

    #[Test]
    public function without_a_terminal_a_missing_option_fails_the_command(): void
    {
        $this->artisan('lexia:install', ['--no-interaction' => true])
            ->expectsOutputToContain('Informe --email')
            ->assertFailed();

        $this->assertSame(0, User::acrossAllAccounts()->count());
        $this->assertSame(0, AiModel::query()->count());
    }

    #[Test]
    public function with_a_terminal_it_asks_for_what_is_missing(): void
    {
        $this->artisan('lexia:install')
            ->expectsQuestion('E-mail do administrador', 'equipe@lexia.com.br')
            ->expectsQuestion('Nome do administrador', 'Equipe LexIA')
            ->expectsQuestion('Senha do administrador', 'outra-senha-longa')
            ->expectsQuestion('Chave de API do Gemini', 'AIzaPerguntada')
            ->assertSuccessful();

        $this->assertSame('Equipe LexIA', User::acrossAllAccounts()->where('email', 'equipe@lexia.com.br')->sole()->name);
        $this->assertSame('AIzaPerguntada', AiModel::platformDefault()?->api_key);
    }

    #[Test]
    public function the_gemini_key_fills_both_models(): void
    {
        $this->artisan('lexia:install', [...self::ADMIN, '--gemini-key' => 'AIzaDoDeploy'])
            ->doesntExpectOutputToContain('sem chave de API')
            ->assertSuccessful();

        $this->assertSame('AIzaDoDeploy', AiModel::platformDefault()?->api_key);
        $this->assertSame('AIzaDoDeploy', EmbeddingModel::platformDefault()?->api_key);
    }

    /**
     * Not a failure — the key is not needed to sign in, and staff can type it
     * in the panel —, but not silent either.
     */
    #[Test]
    public function without_a_key_the_models_are_registered_with_a_warning(): void
    {
        $this->artisan('lexia:install', self::ADMIN)
            ->expectsOutputToContain('gemini-3.7-flash está sem chave de API')
            ->expectsOutputToContain('gemini-embedding-001 está sem chave de API')
            ->assertSuccessful();

        $this->assertNull(AiModel::platformDefault()?->api_key);
    }

    #[Test]
    public function the_gemini_key_never_replaces_one_typed_in_the_panel(): void
    {
        AiModel::factory()->asDefault()->create(['name' => 'gemini-3.7-flash', 'api_key' => 'AIzaDoPainel']);

        $this->artisan('lexia:install', [...self::ADMIN, '--gemini-key' => 'AIzaDoDeploy'])->assertSuccessful();

        $this->assertSame('AIzaDoPainel', AiModel::platformDefault()?->api_key);
        $this->assertSame('AIzaDoDeploy', EmbeddingModel::platformDefault()?->api_key);
    }

    #[Test]
    public function an_invalid_admin_is_refused_before_anything_is_written(): void
    {
        $this->artisan('lexia:install', [...self::ADMIN, '--email' => 'não é e-mail', '--password' => 'curta'])
            ->assertFailed();

        $this->assertSame(0, User::acrossAllAccounts()->count());
    }

    #[Test]
    public function an_email_already_registered_is_left_alone(): void
    {
        [, $owner] = $this->accountWithOwner();
        $hash = $owner->password;

        $this->artisan('lexia:install', [...self::ADMIN, '--email' => $owner->email])
            ->expectsOutputToContain('já está cadastrado — passo pulado')
            ->assertSuccessful();

        $owner->refresh();

        $this->assertSame($hash, $owner->password);
        $this->assertSame(UserRole::AccountAdmin, $owner->role);
    }

    #[Test]
    public function it_recreates_the_platform_account_when_it_is_missing(): void
    {
        DB::table('accounts')->where('id', Account::PLATFORM_ID)->delete();

        $this->artisan('lexia:install', self::ADMIN)
            ->expectsOutputToContain('Conta da LexIA: criada.')
            ->assertSuccessful();

        $account = Account::platform();

        $this->assertSame(AccountType::Platform, $account->type);
        $this->assertTrue($account->isOperational());
    }
}
