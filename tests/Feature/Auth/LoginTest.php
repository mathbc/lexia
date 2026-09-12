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

    #[Test]
    public function guests_are_redirected_away_from_the_panel(): void
    {
        $this->get('/painel')->assertRedirect('/login');
    }
}
