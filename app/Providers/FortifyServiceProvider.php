<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Users\Actions\Auth\CreateNewUser;
use App\Domain\Users\Actions\Auth\ResetUserPassword;
use App\Domain\Users\Actions\Auth\UpdateUserPassword;
use App\Domain\Users\Actions\Auth\UpdateUserProfileInformation;
use App\Domain\Users\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerViews();
        $this->registerAuthentication();

        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip()
            );
        });
    }

    /**
     * Fortify owns the routes; the screens behind them are ours.
     */
    private function registerViews(): void
    {
        Fortify::loginView(fn () => Inertia::render('auth/login', [
            'status' => session('status'),
        ]));

        Fortify::requestPasswordResetLinkView(fn () => Inertia::render('auth/forgot-password', [
            'status' => session('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->input('email'),
            'token' => $request->route('token'),
        ]));

        Fortify::verifyEmailView(fn () => Inertia::render('auth/verify-email', [
            'status' => session('status'),
        ]));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * Authentication carries two business rules beyond the password check: a
     * disabled user cannot sign in, and neither can anyone whose account has
     * been switched off — by the customer or by LexIA.
     *
     * Both are enforced here rather than in middleware so that a rejected
     * login never establishes a session in the first place.
     */
    private function registerAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::acrossAllAccounts()
                ->with('account')
                ->where('email', strtolower((string) $request->input(Fortify::username())))
                ->first();

            if (! $user || ! Hash::check((string) $request->input('password'), $user->password)) {
                return null;
            }

            if (! $user->enabled) {
                throw ValidationException::withMessages([
                    Fortify::username() => 'Este usuário está desabilitado. Fale com o administrador da sua conta.',
                ]);
            }

            if (! $user->account->isOperational()) {
                throw ValidationException::withMessages([
                    Fortify::username() => 'Esta conta está inativa. Fale com o suporte da LexIA.',
                ]);
            }

            return $user;
        });
    }
}
