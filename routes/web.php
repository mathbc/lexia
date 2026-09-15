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
});
