<?php

declare(strict_types=1);

use App\Domain\Accounts\Actions\RegisterAccountWithOwner;
use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Users\Enums\UserType;
use App\Domain\Accounts\Actions\ShowAccount;
use App\Domain\Accounts\Actions\ToggleAccountStatus;
use App\Domain\Accounts\Actions\UpdateAccount;
use App\Domain\Users\Actions\CreateUser;
use App\Domain\Users\Actions\ListUsers;
use App\Domain\Users\Actions\ShowUserForm;
use App\Domain\Users\Actions\ToggleUserStatus;
use App\Domain\Users\Actions\UpdateUser;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// URLs are in Portuguese for the people using the product; the Actions behind
// them keep English names.

Route::get('/', fn () => Inertia::render('welcome'))->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/cadastro', fn () => Inertia::render('auth/register', [
        'accountTypes' => AccountType::options(),
        'states' => BrazilianState::options(),
        'userTypes' => UserType::options(),
    ]))->name('register');
    Route::post('/cadastro', RegisterAccountWithOwner::class);
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/painel', fn () => Inertia::render('dashboard'))->name('dashboard');

    Route::get('/conta/{account}', ShowAccount::class)->name('accounts.edit');
    Route::put('/conta/{account}', UpdateAccount::class)->name('accounts.update');
    Route::patch('/conta/{account}/status', ToggleAccountStatus::class)->name('accounts.toggle');

    Route::get('/usuarios', ListUsers::class)->name('users.index');
    Route::get('/usuarios/novo', ShowUserForm::class)->name('users.create');
    Route::post('/usuarios', CreateUser::class)->name('users.store');
    Route::get('/usuarios/{user}/editar', ShowUserForm::class)->name('users.edit');
    Route::put('/usuarios/{user}', UpdateUser::class)->name('users.update');
    Route::patch('/usuarios/{user}/status', ToggleUserStatus::class)->name('users.toggle');
});
