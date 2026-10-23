<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Accounts\Models\Account;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class LoginTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_login_screen_renders(): void
    {
        $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page->component('auth/login'));
    }

    /**
     * Fortify reports a success as `session('status')`; the screen shows it as
     * the toast every other success is, and so it travels as Inertia's flash.
     */
    #[Test]
    public function a_fortify_status_arrives_as_the_success_toast(): void
    {
        $this->withSession(['status' => 'Sua senha foi redefinida.'])
            ->get('/login')
            ->assertInertia(fn ($page) => $page
                ->component('auth/login')
                ->hasFlash('success', 'Sua senha foi redefinida.')
                ->missing('status'));

        $this->actingAs(User::factory()->unverified()->create())
            ->withSession(['status' => 'verification-link-sent'])
            ->get('/email/verify')
            ->assertInertia(fn ($page) => $page
                ->component('auth/verify-email')
                ->hasFlash('success', 'Um novo link foi enviado.'));
    }

    #[Test]
    public function a_valid_user_can_sign_in(): void
    {
        $user = User::factory()->create(['email' => 'ana@lexia.test']);

        $this->post('/login', ['email' => 'ana@lexia.test', 'password' => 'password'])
            ->assertRedirect('/painel');

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function a_disabled_user_is_refused(): void
    {
        User::factory()->disabled()->create(['email' => 'fora@lexia.test']);

        $this->post('/login', ['email' => 'fora@lexia.test', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    #[Test]
    public function a_user_of_a_suspended_account_is_refused(): void
    {
        $account = Account::factory()->disabled()->create();
        User::factory()->forAccount($account)->create(['email' => 'suspenso@lexia.test']);

        $this->post('/login', ['email' => 'suspenso@lexia.test', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    #[Test]
    public function a_user_of_a_deactivated_account_is_refused(): void
    {
        $account = Account::factory()->inactive()->create();
        User::factory()->forAccount($account)->create(['email' => 'inativo@lexia.test']);

        $this->post('/login', ['email' => 'inativo@lexia.test', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    #[Test]
    public function a_wrong_password_is_refused(): void
    {
        User::factory()->create(['email' => 'ana@lexia.test']);

        $this->post('/login', ['email' => 'ana@lexia.test', 'password' => 'errada'])
            ->assertSessionHasErrors();

        $this->assertGuest();
    }

    /**
     * Keyed by IP alone: a client that changes the e-mail on every attempt is
     * throttled all the same. The lockout reaches the form as an error under
     * the e-mail, not as Inertia's modal around a bare 429.
     */
    #[Test]
    public function the_login_form_takes_ten_attempts_a_minute_per_ip(): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['email' => 'ana@lexia.test']);

        foreach (range(1, 10) as $attempt) {
            $this->post('/login', ['email' => "tentativa{$attempt}@lexia.test", 'password' => 'errada'])
                ->assertSessionHasErrors(['email' => __('auth.failed')]);
        }

        $this->from('/login')
            ->post('/login', ['email' => 'ana@lexia.test', 'password' => 'password'])
            ->assertStatus(302)
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => __('auth.throttle', ['seconds' => 60])]);
        $this->assertGuest();

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->post('/login', ['email' => 'ana@lexia.test', 'password' => 'password'])
            ->assertRedirect('/painel');
        $this->assertAuthenticatedAs($user);
    }

    /**
     * The public sign-up is switched off on purpose. Reopening it is a
     * decision, and this test is the one to delete when it is taken.
     */
    #[Test]
    public function the_public_sign_up_is_closed(): void
    {
        $this->get('/cadastro')->assertNotFound();
        $this->post('/cadastro')->assertNotFound();
    }

    #[Test]
    public function guests_are_redirected_away_from_the_panel(): void
    {
        $this->get('/painel')->assertRedirect('/login');
    }
}
