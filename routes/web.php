<?php

declare(strict_types=1);

use App\Domain\Accounts\Actions\CreateAccount;
use App\Domain\Accounts\Actions\ListAccounts;
use App\Domain\Accounts\Actions\RegisterAccountWithOwner;
use App\Domain\Accounts\Actions\ShowAccount;
use App\Domain\Accounts\Actions\ShowAccountForm;
use App\Domain\Accounts\Actions\ToggleAccountStatus;
use App\Domain\Accounts\Actions\UpdateAccount;
use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Customers\Actions\CreateCustomer;
use App\Domain\Customers\Actions\DeleteCustomer;
use App\Domain\Customers\Actions\ListCustomers;
use App\Domain\Customers\Actions\ShowCustomer;
use App\Domain\Customers\Actions\ShowCustomerForm;
use App\Domain\Customers\Actions\UpdateCustomer;
use App\Domain\LegalCases\Actions\AdvanceLegalCaseStep;
use App\Domain\LegalCases\Actions\CreateLegalCase;
use App\Domain\LegalCases\Actions\ListLegalCases;
use App\Domain\LegalCases\Actions\SaveLegalCaseRequirements;
use App\Domain\LegalCases\Actions\ShowAssistedLegalCaseForm;
use App\Domain\LegalCases\Actions\ShowLegalCaseForm;
use App\Domain\LegalCases\Actions\UpdateLegalCaseBasics;
use App\Domain\LegalCases\Actions\UpdateLegalCaseDefendant;
use App\Domain\LegalCases\Actions\UpdateLegalCaseFacts;
use App\Domain\Users\Actions\CreateUser;
use App\Domain\Users\Actions\ListUsers;
use App\Domain\Users\Actions\ShowUserForm;
use App\Domain\Users\Actions\ToggleUserStatus;
use App\Domain\Users\Actions\UpdateUser;
use App\Domain\Users\Enums\UserType;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// URLs are in Portuguese for the people using the product; the Actions behind
// them keep English names.

Route::get('/', fn () => Inertia::render('welcome'))->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/cadastro', fn () => Inertia::render('auth/register', [
        'accountTypes' => AccountType::customerOptions(),
        'states' => BrazilianState::options(),
        'userTypes' => UserType::options(),
    ]))->name('register');
    Route::post('/cadastro', RegisterAccountWithOwner::class);
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/painel', fn () => Inertia::render('dashboard'))->name('dashboard');

    // O cadastro de contas. As rotas literais vêm antes de /contas/{account}
    // para que "nova" não seja lida como o id de uma conta.
    Route::get('/contas', ListAccounts::class)->name('accounts.index');
    Route::get('/contas/nova', ShowAccountForm::class)->name('accounts.create');
    Route::post('/contas', CreateAccount::class)->name('accounts.store');

    Route::prefix('/contas/{account}')->whereUuid('account')->group(function (): void {
        Route::get('/', ShowAccount::class)->name('accounts.show');
        Route::put('/', UpdateAccount::class)->name('accounts.update');
        Route::patch('/status', ToggleAccountStatus::class)->name('accounts.toggle');

        // Os usuários são uma aba da conta, não um cadastro à parte.
        Route::get('/usuarios', ListUsers::class)->name('users.index');
        Route::get('/usuarios/novo', ShowUserForm::class)->name('users.create');
        Route::post('/usuarios', CreateUser::class)->name('users.store');
        Route::get('/usuarios/{user}/editar', ShowUserForm::class)->name('users.edit');
        Route::put('/usuarios/{user}', UpdateUser::class)->name('users.update');
        Route::patch('/usuarios/{user}/status', ToggleUserStatus::class)->name('users.toggle');
    });

    // Os clientes da conta do próprio ator: não há conta na URL porque não há
    // tela de cliente para a equipe LexIA — a CustomerPolicy fecha a fronteira.
    Route::get('/clientes', ListCustomers::class)->name('customers.index');
    Route::get('/clientes/novo', ShowCustomerForm::class)->name('customers.create');
    Route::post('/clientes', CreateCustomer::class)->name('customers.store');

    Route::prefix('/clientes/{customer}')->whereUuid('customer')->group(function (): void {
        Route::get('/', ShowCustomer::class)->name('customers.show');
        Route::put('/', UpdateCustomer::class)->name('customers.update');
        Route::delete('/', DeleteCustomer::class)->name('customers.destroy');
    });

    // As peças da conta do próprio ator, como os clientes: sem conta na URL,
    // porque não há tela de peça para a equipe LexIA.
    Route::get('/pecas', ListLegalCases::class)->name('legal-cases.index');
    Route::get('/pecas/nova', ShowLegalCaseForm::class)->name('legal-cases.create');

    // O outro caminho da mesma escolha: só o cliente e os fatos, e são os
    // agentes que deduzem o enquadramento antes de devolver o advogado ao
    // assistente acima, já preenchido.
    Route::get('/pecas/nova/inteligente', ShowAssistedLegalCaseForm::class)->name('legal-cases.create-assisted');

    Route::post('/pecas', CreateLegalCase::class)->name('legal-cases.store');

    // O assistente salva etapa a etapa, e cada etapa é um caso de uso com rota
    // própria: é a URL que diz o que está sendo salvo, não um campo do corpo.
    Route::prefix('/pecas/{legalCase}')->whereUuid('legalCase')->group(function (): void {
        Route::get('/editar', ShowLegalCaseForm::class)->name('legal-cases.edit');
        Route::put('/dados-basicos', UpdateLegalCaseBasics::class)->name('legal-cases.basics');
        Route::put('/reu', UpdateLegalCaseDefendant::class)->name('legal-cases.defendant');
        Route::put('/fatos', UpdateLegalCaseFacts::class)->name('legal-cases.facts');
        Route::put('/pedidos', SaveLegalCaseRequirements::class)->name('legal-cases.requirements');

        // Os documentos ainda não persistem, mas o Continuar deles diz uma
        // verdade sobre a peça: ela chegou até ali.
        Route::patch('/etapa', AdvanceLegalCaseStep::class)->name('legal-cases.step');
    });
});
