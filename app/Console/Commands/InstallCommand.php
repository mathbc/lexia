<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Accounts\Actions\EnsurePlatformAccount;
use App\Domain\AiModels\Actions\RegisterDefaultModels;
use App\Domain\AiModels\Models\AiModel;
use App\Domain\AiModels\Models\EmbeddingModel;
use App\Domain\Users\Actions\CreatePlatformAdmin;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * What a new database needs before anyone can sign in: LexIA's account, its
 * first administrator and the models the agents run on.
 *
 * It replaced the seeder because the seeder could not do this job. Laravel
 * Cloud refuses `db:seed`, and the seeder built everything through factories,
 * which need the faker that a production install does not ship. The demo data
 * it made — two firms, their users and clients — is gone with it.
 *
 * Every step is idempotent, so the command can run on every deploy: the
 * account is only created when missing, the admin step is skipped once a
 * platform admin exists and no option asks for another, and the models are
 * only promoted on a catalogue with no default. Without a terminal, which is
 * how a deploy runs it, a missing option fails the command instead of waiting
 * on a prompt nobody will answer.
 *
 * The Gemini key is the exception to that rule: it is not needed to sign in,
 * only to run inference, and staff can type it in /modelos-de-ia afterwards.
 * So `--gemini-key` only fills a registered model that has none, a terminal
 * asks for it when one is missing (blank skips), and a run that ends with a
 * model still keyless warns instead of failing.
 */
final class InstallCommand extends Command
{
    protected $signature = 'lexia:install
                            {--name= : nome do administrador da plataforma}
                            {--email= : e-mail do administrador da plataforma}
                            {--password= : senha do administrador da plataforma}
                            {--gemini-key= : chave de API do Gemini para os modelos padrão que ainda não têm uma}';

    protected $description = 'Garante a conta da LexIA, cria o administrador da plataforma e registra os modelos padrão';

    public function handle(): int
    {
        $this->ensurePlatformAccount();

        if (! $this->createAdmin()) {
            return self::FAILURE;
        }

        $this->registerModels();

        return self::SUCCESS;
    }

    private function ensurePlatformAccount(): void
    {
        $account = EnsurePlatformAccount::run();

        $this->info($account->wasRecentlyCreated
            ? 'Conta da LexIA: criada.'
            : 'Conta da LexIA: já existe.');
    }

    /**
     * @return bool false when the admin could not be created
     */
    private function createAdmin(): bool
    {
        if (! $this->asksForAdmin() && $this->platformAdminExists()) {
            $this->info('Administrador: já existe um admin do sistema — passo pulado.');

            return true;
        }

        $email = $this->resolve('email', fn (): string => text('E-mail do administrador', required: true));

        if ($email === null) {
            return false;
        }

        if ($this->emailTaken($email)) {
            $this->info("Administrador: {$email} já está cadastrado — passo pulado, a senha não foi alterada.");

            return true;
        }

        return $this->createAdminWith($email);
    }

    private function createAdminWith(string $email): bool
    {
        $name = $this->resolve('name', fn (): string => text('Nome do administrador', required: true));
        $password = $this->resolve('password', fn (): string => password('Senha do administrador', required: true));

        if ($name === null || $password === null) {
            return false;
        }

        $input = ['name' => $name, 'email' => CreatePlatformAdmin::normalizeEmail($email), 'password' => $password];
        $validator = Validator::make($input, CreatePlatformAdmin::rules(), [], CreatePlatformAdmin::attributes());

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return false;
        }

        $admin = CreatePlatformAdmin::run($name, $email, $password);

        $this->info("Administrador: {$admin->email} criado.");

        return true;
    }

    private function registerModels(): void
    {
        ['text' => $text, 'embedding' => $embedding] = RegisterDefaultModels::run($this->geminiKey());

        $this->info($this->modelLine('Modelo de IA', $text, AiModel::platformDefault()));
        $this->info($this->modelLine('Modelo de embedding', $embedding, EmbeddingModel::platformDefault()));

        foreach ([$text, $embedding] as $model) {
            if (! $model->hasApiKey()) {
                $this->warn("{$model->name} está sem chave de API: rode de novo com --gemini-key ou informe-a em \"Editar\", no painel.");
            }
        }

        // Promoted on this run, so MakeEmbeddingModelDefault queued the
        // catalogues — and on a deploy the queue may have nobody listening.
        if ($embedding->wasChanged('default')) {
            $this->line('Os catálogos foram enfileirados para vetorização: mantenha um worker de fila rodando.');
        }
    }

    /**
     * The option, or the answer to the prompt when a terminal is there and a
     * model would be left without a key; null to leave the keys as they are.
     */
    private function geminiKey(): ?string
    {
        $key = trim((string) $this->option('gemini-key'));

        if ($key === '' && $this->input->isInteractive() && RegisterDefaultModels::lacksKey()) {
            $key = trim(password('Chave de API do Gemini', hint: 'Em branco para informar depois, no painel.'));
        }

        return $key === '' ? null : $key;
    }

    private function modelLine(string $kind, AiModel|EmbeddingModel $model, AiModel|EmbeddingModel|null $default): string
    {
        $state = $model->wasRecentlyCreated ? 'cadastrado' : 'já cadastrado';

        $current = $default?->is($model)
            ? 'é o padrão'
            : 'o padrão continua '.($default->name ?? 'indefinido');

        return "{$kind}: {$model->name} {$state} ({$current}).";
    }

    /**
     * The option, or the answer to the prompt when there is a terminal to ask
     * on; null, with the error printed, when there is neither.
     *
     * @param  callable(): string  $ask
     */
    private function resolve(string $option, callable $ask): ?string
    {
        $value = $this->option($option);

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        if ($this->input->isInteractive()) {
            return $ask();
        }

        $this->error("Informe --{$option}: sem terminal interativo não há como perguntar.");

        return null;
    }

    private function asksForAdmin(): bool
    {
        foreach (['name', 'email', 'password'] as $option) {
            if (filled($this->option($option))) {
                return true;
            }
        }

        return false;
    }

    private function platformAdminExists(): bool
    {
        return User::acrossAllAccounts()->where('role', UserRole::PlatformAdmin)->exists();
    }

    /**
     * Trashed users count: the unique index on `users.email` does too.
     */
    private function emailTaken(string $email): bool
    {
        return User::acrossAllAccounts()
            ->withTrashed()
            ->where('email', CreatePlatformAdmin::normalizeEmail($email))
            ->exists();
    }
}
